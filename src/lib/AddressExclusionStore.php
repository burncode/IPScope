<?php
declare(strict_types=1);
namespace IPScope;

/** Persistent, user-managed IPv4 exclusions; never mutates Docker or templates. */
final class AddressExclusionStore
{
    private string $path;
    public function __construct(string $path) { $this->path = $path; }

    /** @return list<array<string,mixed>> */
    public function load(): array
    {
        if (!is_file($this->path)) return [];
        if (!is_readable($this->path)) throw new \RuntimeException('IPScope exclusion file is not readable.');
        $contents = file_get_contents($this->path);
        if ($contents === false || trim($contents) === '') throw new \RuntimeException('IPScope exclusion file is empty or unreadable.');
        try { $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new \RuntimeException('IPScope exclusion file contains invalid JSON.', 0, $error); }
        if (!is_array($document) || (int)($document['schema_version']??0)!==1 || !is_array($document['rules']??null))
            throw new \RuntimeException('IPScope exclusion file has an unsupported structure.');
        return $this->normalizeRules($document['rules']);
    }

    /** @param array<mixed> $rules @return list<array<string,mixed>> */
    public function save(array $rules): array
    {
        $normalized=$this->normalizeRules($rules);
        $dir=dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir)) throw new \RuntimeException('Unable to create IPScope configuration directory.');
        $json=json_encode(['schema_version'=>1,'updated_at'=>date(DATE_ATOM),'rules'=>$normalized],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $tmp=$this->path.'.tmp.'.getmypid();
        if (file_put_contents($tmp,$json.PHP_EOL,LOCK_EX)===false) { @unlink($tmp); throw new \RuntimeException('Unable to write IPScope exclusion rules.'); }
        @chmod($tmp,0640);
        if (!rename($tmp,$this->path)) { @unlink($tmp); throw new \RuntimeException('Unable to publish IPScope exclusion rules.'); }
        return $normalized;
    }

    /** @param array<mixed> $rules @return list<array<string,mixed>> */
    private function normalizeRules(array $rules): array
    {
        $out=[]; $ids=[]; $calculator=new IPv4Calculator();
        foreach ($rules as $index=>$rule) {
            if (!is_array($rule)) throw new \InvalidArgumentException('Exclusion rule #'.($index+1).' must be an object.');
            $id=trim((string)($rule['id']??''));
            if ($id==='') $id=bin2hex(random_bytes(8));
            if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/',$id) || isset($ids[$id])) throw new \InvalidArgumentException('Exclusion rule IDs must be unique and 8–64 safe characters.');
            $ids[$id]=true;
            $network=trim((string)($rule['network']??'*'));
            if ($network==='' || strlen($network)>128) throw new \InvalidArgumentException('Exclusion rule network must be * or a Docker network name.');
            $type=(string)($rule['match_type']??'ip');
            if (!in_array($type,['ip','cidr'],true)) throw new \InvalidArgumentException('Exclusion match_type must be ip or cidr.');
            $value=trim((string)($rule['value']??''));
            if ($type==='ip') {
                if (filter_var($value,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)===false) throw new \InvalidArgumentException('Exclusion IP must be a valid IPv4 address.');
            } else {
                try { $calculator->range($value); } catch (\InvalidArgumentException $e) { throw new \InvalidArgumentException('Exclusion CIDR must be a valid IPv4 subnet.',0,$e); }
            }
            $reason=trim((string)($rule['reason']??''));
            if ($reason==='' || strlen($reason)>160) throw new \InvalidArgumentException('Exclusion reason is required and must be at most 160 characters.');
            $enabled=$rule['enabled']??true;
            if (!is_bool($enabled)) throw new \InvalidArgumentException('Exclusion enabled must be a boolean.');
            $out[]=['id'=>$id,'network'=>$network,'match_type'=>$type,'value'=>$value,'reason'=>$reason,'enabled'=>$enabled,'created_at'=>(string)($rule['created_at']??date(DATE_ATOM))];
        }
        return $out;
    }
}
