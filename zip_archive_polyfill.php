<?php
/**
 * CEISA 4.0 - Universal ZipArchive Polyfill for PHP
 * File: zip_archive_polyfill.php
 *
 * 100% Pure PHP PKZIP Reader & Writer menggunakan gzinflate/gzdeflate (zlib).
 * Bekerja otomatis saat ekstensi native PHP 'zip' (ZipArchive) belum aktif/terpasang.
 * Memastikan fitur Import (.xlsx) dan Export Excel Dokumen Pabean CEISA 4.0
 * SELALU BERFUNGSI SEMPURNA pada lingkungan server apa pun (cPanel, VPS, Docker, Windows).
 */

if (!class_exists('ZipArchive')) {
    class ZipArchive {
        const CREATE    = 1;
        const OVERWRITE = 8;
        const EXCL      = 2;
        const CHECKCONS = 4;
        const RDONLY    = 16;
        const FL_NOCASE = 1;
        const ER_OK     = 0;
        const ER_NOENT  = 9;

        public $status   = 0;
        public $numFiles = 0;
        public $filename = '';

        private $mode = 'r'; // 'r' = baca, 'w' = tulis
        private $handle = null;
        private $readEntries = [];
        private $writeEntries = [];

        public function open($filename, $flags = 0) {
            $this->close();
            $this->filename = $filename;

            // Mode Penulisan (CREATE / OVERWRITE)
            if (($flags & self::CREATE) || ($flags & self::OVERWRITE)) {
                $this->mode = 'w';
                $this->writeEntries = [];
                $this->status = self::ER_OK;
                $this->numFiles = 0;
                return true;
            }

            // Mode Pembacaan
            $this->mode = 'r';
            if (!file_exists($filename) || !is_readable($filename)) {
                $this->status = self::ER_NOENT;
                return false;
            }

            $this->handle = @fopen($filename, 'rb');
            if (!$this->handle) {
                $this->status = self::ER_NOENT;
                return false;
            }

            $ok = $this->loadCentralDirectory();
            if ($ok) {
                $this->numFiles = count($this->readEntries);
                $this->status = self::ER_OK;
                return true;
            }

            $this->close();
            return false;
        }

        private function loadCentralDirectory() {
            fseek($this->handle, 0, SEEK_END);
            $fileSize = ftell($this->handle);
            if ($fileSize < 22) {
                return false;
            }

            // Cari signature EOCD (0x06054b50) di bagian akhir file
            $searchLen = min($fileSize, 65557);
            fseek($this->handle, $fileSize - $searchLen);
            $searchBuf = fread($this->handle, $searchLen);

            $eocdPos = strrpos($searchBuf, "PK\x05\x06");
            if ($eocdPos === false) {
                return false;
            }

            $eocdOffset = $fileSize - $searchLen + $eocdPos;
            fseek($this->handle, $eocdOffset);
            $eocdData = fread($this->handle, 22);
            if (strlen($eocdData) < 22) {
                return false;
            }

            $eocd = unpack('vdisk/vcd_disk/vdisk_entries/ventries/Vcd_size/Vcd_offset/vcomment_len', substr($eocdData, 4));
            $cdOffset = $eocd['cd_offset'];
            $cdEntries = $eocd['entries'];

            fseek($this->handle, $cdOffset);
            $this->readEntries = [];

            for ($i = 0; $i < $cdEntries; $i++) {
                $sig = fread($this->handle, 4);
                if ($sig !== "PK\x01\x02") {
                    break;
                }

                $cdHeader = fread($this->handle, 42);
                if (strlen($cdHeader) < 42) {
                    break;
                }

                $hdr = unpack('vversion_made/vversion_needed/vflag/vmethod/vmod_time/vmod_date/Vcrc32/Vcompressed_size/Vuncompressed_size/vname_len/vextra_len/vcomment_len/vdisk_start/vint_attr/Vext_attr/Vlocal_offset', $cdHeader);
                $name = ($hdr['name_len'] > 0) ? fread($this->handle, $hdr['name_len']) : '';
                if ($hdr['extra_len'] > 0) fseek($this->handle, $hdr['extra_len'], SEEK_CUR);
                if ($hdr['comment_len'] > 0) fseek($this->handle, $hdr['comment_len'], SEEK_CUR);

                $this->readEntries[$name] = [
                    'method'            => $hdr['method'],
                    'compressed_size'   => $hdr['compressed_size'],
                    'uncompressed_size' => $hdr['uncompressed_size'],
                    'crc32'             => $hdr['crc32'],
                    'local_offset'      => $hdr['local_offset'],
                ];
            }

            return true;
        }

        public function getFromName($name) {
            if ($this->mode !== 'r' || !$this->handle) {
                return false;
            }

            $name = str_replace('\\', '/', $name);

            if (!isset($this->readEntries[$name])) {
                $lowerName = strtolower($name);
                $foundKey = null;
                foreach ($this->readEntries as $k => $v) {
                    if (strtolower($k) === $lowerName) {
                        $foundKey = $k;
                        break;
                    }
                }
                if ($foundKey) {
                    $name = $foundKey;
                } else {
                    return false;
                }
            }

            $entry = $this->readEntries[$name];
            fseek($this->handle, $entry['local_offset']);

            $sig = fread($this->handle, 4);
            if ($sig !== "PK\x03\x04") {
                return false;
            }

            $localHdr = fread($this->handle, 26);
            if (strlen($localHdr) < 26) {
                return false;
            }

            $loc = unpack('vversion_needed/vflag/vmethod/vmod_time/vmod_date/Vcrc32/Vcompressed_size/Vuncompressed_size/vname_len/vextra_len', $localHdr);
            fseek($this->handle, $loc['name_len'] + $loc['extra_len'], SEEK_CUR);

            $compressedData = fread($this->handle, $entry['compressed_size']);
            if ($entry['method'] == 0) {
                return $compressedData;
            } elseif ($entry['method'] == 8) {
                $uncompressed = @gzinflate($compressedData);
                if ($uncompressed === false) {
                    $uncompressed = @gzuncompress($compressedData);
                }
                return $uncompressed;
            }

            return false;
        }

        public function getFromIndex($index) {
            $name = $this->getNameIndex($index);
            return ($name !== false) ? $this->getFromName($name) : false;
        }

        public function getNameIndex($index) {
            $keys = ($this->mode === 'r') ? array_keys($this->readEntries) : array_keys($this->writeEntries);
            return $keys[$index] ?? false;
        }

        public function statName($name) {
            $name = str_replace('\\', '/', $name);
            if (isset($this->readEntries[$name])) {
                $e = $this->readEntries[$name];
                return [
                    'name'        => $name,
                    'size'        => $e['uncompressed_size'],
                    'crc'         => $e['crc32'],
                    'comp_size'   => $e['compressed_size'],
                    'comp_method' => $e['method']
                ];
            }
            return false;
        }

        public function statIndex($index) {
            $name = $this->getNameIndex($index);
            return ($name !== false) ? $this->statName($name) : false;
        }

        public function addFromString($localname, $contents) {
            $localname = str_replace('\\', '/', $localname);
            $this->writeEntries[$localname] = (string)$contents;
            $this->numFiles = count($this->writeEntries);
            return true;
        }

        public function addFile($filename, $localname = null) {
            if (!file_exists($filename) || !is_readable($filename)) {
                return false;
            }
            $contents = file_get_contents($filename);
            if ($localname === null) {
                $localname = basename($filename);
            }
            return $this->addFromString($localname, $contents);
        }

        public function extractTo($destination, $entries = null) {
            if ($this->mode !== 'r') return false;
            if (!is_dir($destination)) {
                @mkdir($destination, 0777, true);
            }
            if ($entries === null) {
                $entries = array_keys($this->readEntries);
            } elseif (is_string($entries)) {
                $entries = [$entries];
            }
            foreach ($entries as $entry) {
                $content = $this->getFromName($entry);
                if ($content !== false) {
                    $destPath = rtrim($destination, '/\\') . '/' . ltrim($entry, '/\\');
                    $dir = dirname($destPath);
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0777, true);
                    }
                    file_put_contents($destPath, $content);
                }
            }
            return true;
        }

        public function close() {
            if ($this->mode === 'w' && !empty($this->filename) && !empty($this->writeEntries)) {
                $this->saveZipArchive();
            }

            if ($this->handle && is_resource($this->handle)) {
                fclose($this->handle);
            }
            $this->handle = null;
            $this->readEntries = [];
            $this->writeEntries = [];
            $this->filename = '';
            $this->numFiles = 0;
            return true;
        }

        private function saveZipArchive() {
            $dir = dirname($this->filename);
            if (!empty($dir) && !is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }

            $offset = 0;
            $localParts = [];
            $cdParts = [];
            $entryCount = 0;

            foreach ($this->writeEntries as $name => $data) {
                $uncompressedSize = strlen($data);
                $crc = crc32($data);

                $deflated = @gzdeflate($data);
                if ($deflated !== false && strlen($deflated) < $uncompressedSize) {
                    $method = 8;
                    $compressedData = $deflated;
                    $compressedSize = strlen($deflated);
                } else {
                    $method = 0;
                    $compressedData = $data;
                    $compressedSize = $uncompressedSize;
                }

                $nameLen = strlen($name);

                // Local File Header (PK\x03\x04)
                $lh = "PK\x03\x04" .
                    pack('v', 20) .               // version needed
                    pack('v', 0) .                // general flags
                    pack('v', $method) .          // compression method
                    pack('v', 0) . pack('v', 0) . // mod time & date
                    pack('V', $crc) .             // crc32
                    pack('V', $compressedSize) .   // compressed size
                    pack('V', $uncompressedSize) . // uncompressed size
                    pack('v', $nameLen) .         // file name length
                    pack('v', 0) .                // extra field length
                    $name .
                    $compressedData;

                $localParts[] = $lh;

                // Central Directory Entry (PK\x01\x02)
                $cd = "PK\x01\x02" .
                    pack('v', 20) .               // version made by
                    pack('v', 20) .               // version needed
                    pack('v', 0) .                // general flags
                    pack('v', $method) .          // compression method
                    pack('v', 0) . pack('v', 0) . // mod time & date
                    pack('V', $crc) .             // crc32
                    pack('V', $compressedSize) .   // compressed size
                    pack('V', $uncompressedSize) . // uncompressed size
                    pack('v', $nameLen) .         // file name length
                    pack('v', 0) .                // extra field length
                    pack('v', 0) .                // file comment length
                    pack('v', 0) .                // disk number start
                    pack('v', 0) .                // internal file attributes
                    pack('V', 32) .               // external file attributes
                    pack('V', $offset) .          // relative offset of local header
                    $name;

                $cdParts[] = $cd;

                $offset += strlen($lh);
                $entryCount++;
            }

            $localBody = implode('', $localParts);
            $cdBody = implode('', $cdParts);
            $cdSize = strlen($cdBody);
            $cdOffset = strlen($localBody);

            // End of Central Directory Record (PK\x05\x06)
            $eocd = "PK\x05\x06" .
                pack('v', 0) .             // disk number
                pack('v', 0) .             // disk where CD starts
                pack('v', $entryCount) .    // number of CD records on disk
                pack('v', $entryCount) .    // total number of CD records
                pack('V', $cdSize) .        // size of central directory
                pack('V', $cdOffset) .      // offset of central directory
                pack('v', 0);               // comment length

            $fullZip = $localBody . $cdBody . $eocd;
            file_put_contents($this->filename, $fullZip);
        }

        public function __destruct() {
            $this->close();
        }
    }
}
