<?php
declare(strict_types=1);

namespace IPScope;

final class TemplateParser
{
    /**
     * Read Unraid user templates. A template reservation is configuration,
     * not proof that Docker currently has an active endpoint for the address.
     *
     * @return list<array<string,mixed>>
     */
    public function collect(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new \\RuntimeException('Unraid user template directory does not exist.');
        }
        if (!is_readable($directory)) {
            throw new \\RuntimeException('Unraid user template directory is not readable.');
        }

        $files = glob(rtrim($directory, '/') . '/*.xml') ?: [];
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        $templates = [];

        foreach ($files as $file) {
            $previous = libxml_use_internal_errors(true);
            try {
                $xml = simplexml_load_file($file, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
                if ($xml === false) {
                    continue;
                }

                $name = trim((string)($xml->Name ?? ''));
                if ($name === '') {
                    $name = pathinfo($file, PATHINFO_FILENAME);
                }

                $templates[] = [
                    'name' => $name,
                    'network' => trim((string)($xml->Network ?? '')),
                    'ips' => $this->parseIpList((string)($xml->MyIP ?? '')),
                    'mac' => $this->validMac((string)($xml->MyMAC ?? '')),
                    'source' => 'unraid_template',
                    'path' => basename($file),
                ];
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }

        return $templates;
    }

    /** @return list<string> */
    private function parseIpList(string $value): array
    {
        $parts = preg_split('/[,;\s]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ips = [];
        foreach ($parts as $part) {
            if (filter_var($part, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $ips[] = $part;
            }
        }
        return array_values(array_unique($ips));
    }

    private function validMac(string $value): ?string
    {
        $value = trim($value);
        return preg_match('/^(?:[0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/', $value) ? strtolower($value) : null;
    }
}
