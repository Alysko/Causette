<?php
use Causette\Security\ConversationRead;

require_once __DIR__ . '/../core/ConversationRead.php';

class AttachmentController extends Controller {
    private const EXT_CONTENT_TYPE = [
        'jpg'  => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'pdf'  => 'application/pdf',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip'  => 'application/zip',
        '7z'   => 'application/x-7z-compressed',
    ];

    private const VIDEO_EXTENSIONS = ['mp4', 'webm'];
    private const INLINE_EXTENSIONS = ['jpg', 'png', 'webp', ...self::VIDEO_EXTENSIONS];

    public function serve($params) {
        header('Cache-Control: private, no-store');
        Auth::requireAuth();
        $viewer = Auth::user();
        if (!$viewer) {
            ErrorHandler::abort(404, 'Not found');
        }

        $userNumber = (string)($params['user_number'] ?? '');
        $filename   = (string)($params['filename'] ?? '');

        if (!preg_match('/^\d{16}$/', $userNumber)) {
            ErrorHandler::abort(404, 'Not found');
        }

        if (!preg_match('/\A\d{16}\.([a-z0-9]+)\z/', $filename, $m) || !isset(self::EXT_CONTENT_TYPE[$m[1]])) {
            ErrorHandler::abort(404, 'Not found');
        }

        $ext      = $m[1];
        $fileBase = substr($filename, 0, -strlen($ext) - 1);

        $storageBase = defined('STORAGE_FILESYSTEM_ROOT')
            ? rtrim((string)STORAGE_FILESYSTEM_ROOT, '/')
            : dirname(__DIR__, 3) . '/storage';

        // Look up the attachment record. If it is a dedup reference, resolve the
        // physical file location from the source attachment via the LEFT JOINs.
        $record = Database::query(
            "SELECT a.original_name, a.status, a.deleted_at, a.expires_at, a.user_id,
                    c.type, c.non_member_visibility, c.deleted_at AS chat_deleted_at,
                    cm.user_id AS viewer_member, m.id AS linked_message_id,
                    COALESCE(src.file_name,      a.file_name)      AS physical_file_name,
                    COALESCE(src_u.user_number,  u.user_number)    AS physical_user_number
             FROM attachments a
             JOIN users u          ON u.id      = a.user_id
             JOIN chats c          ON c.id      = a.chat_id
             LEFT JOIN chat_members cm ON cm.chat_id = c.id AND cm.user_id = ?
             LEFT JOIN messages m  ON m.id      = a.message_id AND m.chat_id = c.id
             LEFT JOIN attachments src   ON src.id   = a.dedup_source_id
             LEFT JOIN users       src_u ON src_u.id = src.user_id
             WHERE u.user_number = ? AND a.file_name = ? AND a.file_extension = ?
             LIMIT 1",
            [(int)$viewer->id, $userNumber, $fileBase, $ext]
        )->fetch();

        if (!$record || !empty($record->deleted_at)) {
            ErrorHandler::abort(404, 'Not found');
        }

        $expiresAt = (string)($record->expires_at ?? '');
        if ($expiresAt !== '') {
            $expiresAtTimestamp = strtotime($expiresAt);
            if ($expiresAtTimestamp === false || $expiresAtTimestamp <= time()) {
                ErrorHandler::abort(404, 'Not found');
            }
        }

        $chat = (object) [
            'type' => $record->type,
            'non_member_visibility' => $record->non_member_visibility,
            'deleted_at' => $record->chat_deleted_at,
        ];
        $isMember = $record->viewer_member !== null;
        $status = strtolower(trim((string)($record->status ?? '')));
        $mayDownload = match ($status) {
            'pending' => $isMember && empty($chat->deleted_at) && (int)$record->user_id === (int)$viewer->id,
            'submitted' => $record->linked_message_id !== null
                && (ConversationRead::allows($chat, $isMember) || ConversationRead::allowsTrash($chat, $viewer)),
            default => false,
        };
        if (!$mayDownload) {
            ErrorHandler::abort(404, 'Not found');
        }

        $physicalUserNumber = (string)$record->physical_user_number;
        $physicalFileName = (string)$record->physical_file_name;
        if (!preg_match('/\A\d{16}\z/', $physicalUserNumber) || !preg_match('/\A\d{16}\z/', $physicalFileName)) {
            ErrorHandler::abort(404, 'Not found');
        }
        $filePath = $storageBase . '/attachments/' . $physicalUserNumber . '/' . $physicalFileName . '.' . $ext;

        if (!is_file($filePath)) {
            ErrorHandler::abort(404, 'Not found');
        }

        $file = fopen($filePath, 'rb');
        if ($file === false || ($stat = fstat($file)) === false) {
            ErrorHandler::abort(404, 'Not found');
        }
        $size = $stat['size'];
        $range = null;
        if (in_array($ext, self::VIDEO_EXTENSIONS, true)) {
            header('Accept-Ranges: bytes');
            $header = $_SERVER['HTTP_RANGE'] ?? '';
            if ($header !== '') {
                $range = self::parseVideoRange($header, $size);
                if ($range === null) {
                    fclose($file);
                    header('Content-Range: bytes */' . $size);
                    http_response_code(416);
                    exit;
                }
                [$first, $last] = $range;
                http_response_code(206);
                header("Content-Range: bytes $first-$last/$size");
            }
        }
        header('Content-Type: ' . self::EXT_CONTENT_TYPE[$ext]);
        header('X-Content-Type-Options: nosniff');
        $remaining = $range === null ? $size : $last - $first + 1;
        header('Content-Length: ' . $remaining);

        if (!in_array($ext, self::INLINE_EXTENSIONS, true)) {
            $downloadName = ((string)$record->original_name !== '')
                ? preg_replace('/[^A-Za-z0-9 _.-]/', '_', (string)$record->original_name)
                : preg_replace('/[^A-Za-z0-9 _.-]/', '_', $filename);
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if ($range !== null) {
            fseek($file, $first);
        }
        while ($remaining > 0 && !feof($file)) {
            $chunk = fread($file, min(65536, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
        }
        fclose($file);
        exit;
    }

    /** @return array{int, int}|null */
    public static function parseVideoRange(string $header, int $size): ?array {
        if ($size <= 0 || !preg_match('/\Abytes=(\d{0,19})-(\d{0,19})\z/', trim($header), $parts)) {
            return null;
        }
        [, $start, $end] = $parts;
        if ($start === '') {
            return (int)$end > 0 ? [max(0, $size - (int)$end), $size - 1] : null;
        }
        $first = (int)$start;
        $last = $end === '' ? $size - 1 : min($size - 1, (int)$end);
        return $first <= $last ? [$first, $last] : null;
    }
}
