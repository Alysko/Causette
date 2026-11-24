<?php $tr = \Causette\Localization\Locale::current()->html(...); ?>
<div class="p-8 overflow-auto space-y-8 max-w-3xl">

    <div class="flex items-center gap-4">
        <a href="<?= htmlspecialchars(base_url('/apikeys')) ?>"
           class="inline-flex items-center gap-1 text-sm text-zinc-400 hover:text-zinc-200 transition">
            <i class="fa-solid fa-arrow-left"></i>
            <?= $tr('api_help.back') ?>
        </a>
    </div>

    <h1 class="text-3xl font-bold"><?= $tr('api_help.title') ?></h1>
    <p class="text-zinc-400"><?= $tr('api_help.intro') ?></p>

    <!-- Authentication -->
    <section class="space-y-3">
        <h2 class="text-xl font-semibold text-zinc-100"><?= $tr('api_help.authentication') ?></h2>
        <p class="text-sm text-zinc-300">
            <?= $tr('api_help.authentication_fields', ['parameter' => 'api_key', 'header' => 'X-API-Key']) ?>
        </p>
        <p class="text-sm text-zinc-400">
            <?= $tr('api_help.key_handling') ?>
        </p>
    </section>

    <!-- Send Message -->
    <section class="space-y-4">
        <h2 class="text-xl font-semibold text-zinc-100"><?= $tr('api_help.send') ?></h2>

        <div class="rounded-xl border border-zinc-700 bg-zinc-800 px-4 py-3">
            <span class="inline-block rounded bg-emerald-600 px-2 py-0.5 text-xs font-bold text-white mr-2">POST</span>
            <code class="text-sm text-zinc-100">/api/bot/send</code>
        </div>

        <h3 class="text-lg font-medium text-zinc-200"><?= $tr('api_help.parameters') ?></h3>
        <div class="overflow-x-auto rounded-xl border border-zinc-700">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-700 bg-zinc-800/50">
                        <th class="px-4 py-3 text-left font-medium text-zinc-300"><?= $tr('api_help.parameter') ?></th>
                        <th class="px-4 py-3 text-left font-medium text-zinc-300"><?= $tr('ui.type') ?></th>
                        <th class="px-4 py-3 text-left font-medium text-zinc-300"><?= $tr('api_help.required') ?></th>
                        <th class="px-4 py-3 text-left font-medium text-zinc-300"><?= $tr('api_help.description') ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800">
                    <tr>
                        <td class="px-4 py-3"><code class="text-emerald-400">api_key</code></td>
                        <td class="px-4 py-3 text-zinc-400"><?= $tr('api_help.string') ?></td>
                        <td class="px-4 py-3"><span class="text-emerald-400"><?= $tr('api_help.yes') ?></span></td>
                        <td class="px-4 py-3 text-zinc-300"><?= $tr('api_help.key_parameter', ['header' => 'X-API-Key']) ?></td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3"><code class="text-emerald-400">chat_number</code></td>
                        <td class="px-4 py-3 text-zinc-400"><?= $tr('api_help.string') ?></td>
                        <td class="px-4 py-3"><span class="text-emerald-400"><?= $tr('api_help.yes') ?></span></td>
                        <td class="px-4 py-3 text-zinc-300"><?= $tr('api_help.chat_parameter', ['formatted' => '1234-5678-9012-3456', 'digits' => '1234567890123456']) ?></td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3"><code class="text-emerald-400">text</code></td>
                        <td class="px-4 py-3 text-zinc-400"><?= $tr('api_help.string') ?></td>
                        <td class="px-4 py-3"><span class="text-emerald-400"><?= $tr('api_help.yes') ?></span></td>
                        <td class="px-4 py-3 text-zinc-300"><?= $tr('api_help.text_parameter') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h3 class="text-lg font-medium text-zinc-200"><?= $tr('api_help.example') ?></h3>
        <p class="text-sm text-zinc-400"><?= $tr('api_help.example_environment', ['variable' => 'CAUSETTE_API_KEY']) ?></p>
        <div class="rounded-xl border border-zinc-700 bg-zinc-900 p-4 overflow-x-auto">
            <pre class="text-sm text-zinc-300 whitespace-pre"><code>curl -X POST <?= htmlspecialchars(rtrim(base_url(''), '/')) ?>/api/bot/send \
  -d "api_key=$CAUSETTE_API_KEY" \
  -d "chat_number=1234-5678-9012-3456" \
  -d "text=<?= $tr('api_help.example_message') ?>"</code></pre>
        </div>

        <p class="text-sm text-zinc-400"><?= $tr('api_help.example_header') ?></p>
        <div class="rounded-xl border border-zinc-700 bg-zinc-900 p-4 overflow-x-auto">
            <pre class="text-sm text-zinc-300 whitespace-pre"><code>curl -X POST <?= htmlspecialchars(rtrim(base_url(''), '/')) ?>/api/bot/send \
  -H "X-API-Key: $CAUSETTE_API_KEY" \
  -d "chat_number=1234-5678-9012-3456" \
  -d "text=<?= $tr('api_help.example_message') ?>"</code></pre>
        </div>

        <h3 class="text-lg font-medium text-zinc-200"><?= $tr('api_help.success_response') ?></h3>
        <div class="rounded-xl border border-zinc-700 bg-zinc-900 p-4">
            <pre class="text-sm text-zinc-300"><code>{
  "success": true,
  "message_id": 123
}</code></pre>
        </div>

        <h3 class="text-lg font-medium text-zinc-200"><?= $tr('api_help.error_response') ?></h3>
        <div class="rounded-xl border border-zinc-700 bg-zinc-900 p-4">
            <pre class="text-sm text-zinc-300"><code>{
  "error": "<?= $tr('api_help.example_error_message') ?>"
}</code></pre>
        </div>
    </section>

    <!-- Error Codes -->
    <section class="space-y-3">
        <h2 class="text-xl font-semibold text-zinc-100"><?= $tr('api_help.errors') ?></h2>
        <div class="overflow-x-auto rounded-xl border border-zinc-700">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-700 bg-zinc-800/50">
                        <th class="px-4 py-3 text-left font-medium text-zinc-300"><?= $tr('api_help.http_status') ?></th>
                        <th class="px-4 py-3 text-left font-medium text-zinc-300"><?= $tr('api_help.meaning') ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800">
                    <tr>
                        <td class="px-4 py-3"><code class="text-red-400">400</code></td>
                        <td class="px-4 py-3 text-zinc-300"><?= $tr('api_help.bad_request') ?></td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3"><code class="text-red-400">401</code></td>
                        <td class="px-4 py-3 text-zinc-300"><?= $tr('api_help.unauthorized') ?></td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3"><code class="text-red-400">403</code></td>
                        <td class="px-4 py-3 text-zinc-300"><?= $tr('api_help.forbidden') ?></td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3"><code class="text-red-400">404</code></td>
                        <td class="px-4 py-3 text-zinc-300"><?= $tr('api_help.not_found') ?></td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3"><code class="text-red-400">429</code></td>
                        <td class="px-4 py-3 text-zinc-300"><?= $tr('api_help.rate_limited') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Notes -->
    <section class="space-y-3">
        <h2 class="text-xl font-semibold text-zinc-100"><?= $tr('api_help.notes') ?></h2>
        <ul class="list-disc list-inside text-sm text-zinc-300 space-y-2">
            <li><?= $tr('api_help.ownership') ?></li>
            <li><?= $tr('api_help.identity') ?></li>
            <li><?= $tr('api_help.ip_restrictions') ?></li>
            <li><?= $tr('api_help.audit') ?></li>
            <li><?= $tr('api_help.retention') ?></li>
            <li><?= $tr('api_help.banned') ?></li>
        </ul>
    </section>

</div>
