<?php

namespace App\Support\Livewire;

use Filament\Forms\Contracts\HasForms;
use Livewire\ComponentHook;

/**
 * Guard GLOBAL anti-"resurrection" baris repeater — perbaikan bug Livewire 3.8.
 *
 * Gejala: setelah sebuah item repeater dihapus, mirror data di BROWSER tidak membuang
 * key item itu. Di request berikutnya key tsb dikirim ulang lewat `updates` sehingga
 * item yang sudah dihapus HIDUP LAGI di server → jumlah baris tak berubah → terlihat
 * "hapus baris tidak bisa" (request 200, tanpa error).
 *
 * Hook ini menolak setiap `update` yang menghidupkan kembali key baris ber-UUID yang
 * TIDAK ada pada snapshot saat hydrate (state otoritatif). Berlaku ke SEMUA komponen
 * form Filament (semua repeater, semua halaman) tanpa perlu menyentuh tiap komponen.
 */
class RepeaterResurrectionGuard extends ComponentHook
{
    /** @var array<string,bool> path item uuid yang SAH saat hydrate, mis. "data.rows.<uuid>". */
    protected array $liveItemPaths = [];

    public function hydrate($memo = null): void
    {
        if (! $this->component instanceof HasForms) {
            return;
        }
        $this->liveItemPaths = [];
        foreach ($this->publicArrayProps() as $prop => $value) {
            $this->collect($value, $prop);
        }
    }

    public function update($propertyName, $fullPath, $newValue)
    {
        if (! $this->component instanceof HasForms) {
            return null;
        }

        // Callback dijalankan SETELAH update diterapkan → buang bila baris zombie.
        return function () use ($fullPath): void {
            $path = (string) $fullPath;
            // Cocokkan path yang berujung (atau bercabang di) item ber-UUID.
            if (! preg_match('/^(.*\.[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})(?:\..+)?$/', $path, $m)) {
                return;
            }
            $itemPath = $m[1];
            if (isset($this->liveItemPaths[$itemPath])) {
                return; // baris sah — biarkan
            }
            $this->unsetPath($itemPath); // baris zombie — buang
        };
    }

    /** @return array<string,array<mixed>> properti publik bertipe array pada komponen. */
    protected function publicArrayProps(): array
    {
        $out = [];
        foreach ((new \ReflectionObject($this->component))->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            // Lewati properti statis & yang BELUM ter-inisialisasi (typed property tanpa
            // nilai, mis. EditRecord::$savedDataHash) — mengaksesnya melempar Error.
            if ($p->isStatic() || ! $p->isInitialized($this->component)) {
                continue;
            }
            $v = $p->getValue($this->component);
            if (is_array($v)) {
                $out[$p->getName()] = $v;
            }
        }

        return $out;
    }

    protected function collect(mixed $arr, string $path): void
    {
        if (! is_array($arr)) {
            return;
        }
        foreach ($arr as $key => $val) {
            $child = $path.'.'.$key;
            if (is_string($key) && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $key)) {
                $this->liveItemPaths[$child] = true;
            }
            if (is_array($val)) {
                $this->collect($val, $child);
            }
        }
    }

    /** Buang $component-><prop>[...][uuid] berdasarkan path titik (mis. "data.rows.<uuid>"). */
    protected function unsetPath(string $itemPath): void
    {
        $segments = explode('.', $itemPath);
        $prop = array_shift($segments);
        if (! property_exists($this->component, $prop) || ! is_array($this->component->{$prop})) {
            return;
        }
        $data = $this->component->{$prop};
        $last = array_pop($segments);
        $ref = &$data;
        foreach ($segments as $s) {
            if (! is_array($ref) || ! array_key_exists($s, $ref)) {
                return;
            }
            $ref = &$ref[$s];
        }
        if (is_array($ref)) {
            unset($ref[$last]);
        }
        unset($ref);
        $this->component->{$prop} = $data;
    }
}
