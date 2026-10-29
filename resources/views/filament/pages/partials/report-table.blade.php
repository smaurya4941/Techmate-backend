@if (empty($rows))
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $empty ?? 'No data in this period.' }}</p>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-gray-500 dark:border-white/10 dark:text-gray-400">
                    @foreach ($cols as $key => $label)
                        <th class="py-2 pr-4 font-medium {{ $loop->first ? '' : 'text-right' }}">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                        @foreach ($cols as $key => $label)
                            <td class="py-2 pr-4 {{ $loop->first ? '' : 'text-right tabular-nums' }}">{{ is_numeric($row[$key] ?? null) && ! in_array($key, ['total', 'balance'], true) ? number_format((float) $row[$key]) : (in_array($key, ['total', 'balance'], true) && isset($row['currency']) ? $row['currency'].' '.number_format((float) $row[$key], 2) : ucfirst(str_replace('_', ' ', (string) ($row[$key] ?? '-')))) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
