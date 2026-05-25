<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use DOMDocument;
use DOMXPath;

class MarketScraper
{
    public function scrape(): array
    {
        $html = $this->fetch();
        if (!$html) return [];

        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOERROR);
        $xpath = new DOMXPath($dom);

        $poultry   = $this->dedup($this->parsePoultry($xpath));
        $materials = $this->dedup($this->parseMaterials($xpath));
        $chicks    = $this->dedup($this->parseChicks($xpath));
        $exchange  = $this->dedup($this->parseExchange($xpath));
        $feed = $this->dedup($this->parseFeed($xpath));

        return compact('poultry', 'materials', 'chicks', 'exchange', 'feed');
    }

    private function dedup(array $items): array
    {
        $seen = [];
        $out  = [];
        foreach ($items as $item) {
            if (!isset($seen[$item['name']])) {
                $seen[$item['name']] = true;
                $out[] = $item;
            }
        }
        return $out;
    }

    private function fetch(): ?string
    {
        $resp = Http::withOptions(['verify' => false])->timeout(15)
            ->get('https://www.elmorshdledwagn.com/');
        return $resp->ok() ? $resp->body() : null;
    }

    private function parsePoultry(DOMXPath $xpath): array
    {
        $items = [];
        $cards = $xpath->query("//div[contains(@class,'price-today')]//div[contains(@class,'card')]");

        foreach ($cards as $card) {
            $titleNode = $xpath->query(".//h5[contains(@class,'card-title')]", $card);
            if (!$titleNode->length) continue;
            $name = trim($titleNode->item(0)->textContent);

            $labels = $xpath->query(".//span[contains(@class,'text-muted')]", $card);
            $values = $xpath->query(".//h5[contains(@class,'h1')]", $card);

            $prices = [];
            for ($i = 0; $i < $labels->length && $i < $values->length; $i++) {
                $label = trim($labels->item($i)->textContent);
                $val   = trim($values->item($i)->textContent);
                $prices[$label] = is_numeric($val) ? (float) $val : null;
            }

            $price = null;
            foreach (['سعر', 'أعلى', 'تنفيذ', 'أقل'] as $key) {
                if (isset($prices[$key]) && $prices[$key] !== null && $prices[$key] > 0) {
                    $price = $prices[$key];
                    break;
                }
            }

            if ($name && $price !== null) {
                $items[] = [
                    'name'   => $name,
                    'unit'   => 'كجم',
                    'price'  => $price,
                    'prices' => $prices,
                ];
            }
        }

        return $items;
    }

    private function parseMaterials(DOMXPath $xpath): array
    {
        $items = [];
        $section = $xpath->query("//h2[contains(text(),'الخامات')]/ancestor::div[contains(@class,'card')]");
        if (!$section->length) return $items;

        $rows = $xpath->query(".//table/tbody/tr", $section->item(0));
        foreach ($rows as $row) {
            $cols = $xpath->query("td", $row);
            if ($cols->length < 2) continue;
            $name  = trim($cols->item(0)->textContent);
            $price = trim($cols->item(1)->textContent);
            $num   = is_numeric($price) ? (float) $price : 0;
            if (!$name || $num <= 0) continue;
            $date  = $cols->length >= 3 ? trim($cols->item(2)->textContent) : null;
            $items[] = [
                'name'  => $name,
                'unit'  => 'طن',
                'price' => $num,
                'date'  => $date,
            ];
        }

        return $items;
    }

    private function parseChicks(DOMXPath $xpath): array
    {
        $items = [];
        $section = $xpath->query("//h2[contains(text(),'بورصة الكتاكيت')]/ancestor::div[contains(@class,'card')]");
        if (!$section->length) return $items;

        $rows = $xpath->query(".//table/tbody/tr", $section->item(0));
        foreach ($rows as $row) {
            $cols = $xpath->query("td", $row);
            if ($cols->length < 3) continue;
            $company = trim($cols->item(0)->textContent);
            $price   = trim($cols->item(2)->textContent);
            $num     = is_numeric($price) ? (float) $price : 0;
            if (!$company || $num <= 0) continue;
            $items[] = [
                'name'    => $company,
                'section' => 'بورصة الكتاكيت',
                'unit'    => 'كجم',
                'price'   => $num,
            ];
        }

        return $items;
    }

    private function parseExchange(DOMXPath $xpath): array
    {
        $items = [];
        $section = $xpath->query("//h2[contains(text(),'بورصة الدواجن')]/ancestor::div[contains(@class,'card')]");
        if (!$section->length) return $items;

        $rows = $xpath->query(".//table/tbody/tr", $section->item(0));
        foreach ($rows as $row) {
            $cols = $xpath->query("td", $row);
            if ($cols->length < 4) continue;
            $company = trim($cols->item(0)->textContent);
            $white   = trim($cols->item(2)->textContent);
            $num     = is_numeric($white) ? (float) $white : 0;
            if (!$company || $num <= 0) continue;
            $items[] = [
                'name'    => $company,
                'section' => 'بورصة الدواجن',
                'unit'    => 'كجم',
                'price'   => $num,
            ];
        }

        return $items;
    }

    private function parseFeed(DOMXPath $xpath): array
    {
        $items = [];
        $section = $xpath->query("//h2[contains(text(),'أسعار الأعلاف')]/ancestor::div[contains(@class,'card')]");
        if (!$section->length) return $items;

        $rows = $xpath->query(".//table/tbody/tr", $section->item(0));
        foreach ($rows as $row) {
            $cols = $xpath->query("td", $row);
            if ($cols->length < 4) continue;
            $company = trim($cols->item(0)->textContent);
            $p23     = trim($cols->item(1)->textContent);
            $num     = is_numeric($p23) ? (float) $p23 : 0;
            if (!$company || $num <= 0) continue;
            $items[] = [
                'name'    => $company,
                'section' => 'أسعار الأعلاف',
                'unit'    => 'طن',
                'price'   => $num,
            ];
        }

        return $items;
    }
}
