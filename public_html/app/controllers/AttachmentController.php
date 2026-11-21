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
        'zip'  => 'application/zip',
        '7z'   => 'application/x-7z-compressed',
    ];

    // Extensions that must be downloaded rather than rendered inline
    private const DOWNLOAD_EXTENSIONS = ['mp4', 'webm', 'pdf', 'odt', 'doc', 'docx', 'zip', '7z'];

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

        if (!preg_match('/^\d{16}\.(jpg|png|webp|mp4|webm|pdf|odt|doc|docx|zip|7z)$/', $filename, $m)) {
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

        $contentType = self::EXT_CONTENT_TYPE[$ext] ?? 'application/octet-stream';

        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . filesize($filePath));

        if (in_array($ext, self::DOWNLOAD_EXTENSIONS, true)) {
            $downloadName = ((string)$record->original_name !== '')
                ? preg_replace('/[^A-Za-z0-9 _.-]/', '_', (string)$record->original_name)
                : preg_replace('/[^A-Za-z0-9 _.-]/', '_', $filename);
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        }

        readfile($filePath);
        exit;
    }
}
