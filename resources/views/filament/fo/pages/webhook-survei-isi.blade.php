<div class="space-y-3 text-sm">
    <div><span class="text-gray-500">id:</span> <span class="font-mono">{{ $r->uuid }}</span></div>
    <div>
        <div class="text-gray-500">Body yang dikirim</div>
        <pre class="mt-1 overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs dark:bg-gray-800">{{ json_encode(json_decode($r->body), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
    <div>
        <div class="text-gray-500">Jawaban server survei (HTTP {{ $r->kode_http ?? '—' }})</div>
        <pre class="mt-1 overflow-x-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-xs dark:bg-gray-800">{{ $r->jawaban ?? '—' }}</pre>
    </div>
</div>
