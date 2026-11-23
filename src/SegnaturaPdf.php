<?php

declare(strict_types=1);

namespace Dodaroe\PdfFirme;

use RuntimeException;

/**
 * Aggiunge la stampigliatura di protocollo (fascia rossa a tutta larghezza in testa alla prima pagina, come quella dei sistemi di protocollo) a un PDF già firmato.
 *
 * Il file originale non si tocca: la stampigliatura è un'annotazione «timbro» scritta in coda, come aggiornamento incrementale (nuovi
 * oggetti, nuova tabella dei riferimenti incrociati con /Prev). I byte firmati restano identici, quindi le firme PAdES che c'erano
 * restano leggibili nella copia. Chi chiama tiene l'originale per il protocollo e dà la copia a chi deve avere la segnatura.
 *
 * Legge sia le tabelle xref classiche sia i flussi xref con gli oggetti compressi (ObjStm). Non tratta i PDF cifrati né le pagine
 * ruotate: in quei casi (e in ogni altro errore) ritorna null e chi chiama usa il file com'è.
 */
final class SegnaturaPdf
{
    /** Larghezze di Helvetica per i caratteri da 32 a 126 (millesimi di corpo). */
    private const LARGHEZZE = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    private const CORPO = 14.0;
    private const ALTEZZA_FASCIA = 20.0;
    private const MARGINE = 6.0;

    private string $pdf = '';
    /** @var array<int, array{t: int, a: int, b: int}> t: 0 libero, 1 offset (a) e generazione (b), 2 dentro l'ObjStm a, posizione b */
    private array $xref = [];
    /** @var array<string, array{0: string, 1: int, 2: int}> */
    private array $trailer = [];
    private bool $xrefFlusso = false;
    private int $ultimoStartxref = 0;
    /** @var array<int, string> */
    private array $cacheStm = [];

    /**
     * @return string|null il PDF con la segnatura in coda, null se non è possibile (file cifrato, pagina ruotata, struttura non letta)
     */
    /**
     * @param string $autore il nome che i lettori di PDF mostrano come autore del timbro (annotazione /T)
     */
    public function __construct(private string $autore = 'Segnatura di protocollo')
    {
    }

    public function applica(string $pdf, string $testo): ?string
    {
        $testo = trim($testo);
        if ($testo === '') {
            return null;
        }
        try {
            return $this->timbra($pdf, $testo);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Larghezza in punti del testo in Helvetica al corpo dato (i caratteri fuori dall'ASCII contano come 600). */
    public static function larghezza(string $testo, float $corpo = self::CORPO): float
    {
        $somma = 0;
        foreach (preg_split('//u', $testo, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) {
            $o = strlen($c) === 1 ? ord($c) : 0;
            $somma += $o >= 32 && $o <= 126 ? self::LARGHEZZE[$o - 32] : 600;
        }

        return $somma * $corpo / 1000;
    }

    private function timbra(string $pdf, string $testo): string
    {
        $this->pdf = $pdf;
        $this->xref = [];
        $this->trailer = [];
        $this->xrefFlusso = false;
        $this->cacheStm = [];
        if (strncmp($pdf, '%PDF-', 5) !== 0) {
            throw new RuntimeException('non è un PDF');
        }
        $fine = strrpos($pdf, 'startxref');
        if ($fine === false || !preg_match('/startxref\s+(\d+)/', $pdf, $m, 0, $fine)) {
            throw new RuntimeException('manca startxref');
        }
        $this->ultimoStartxref = (int) $m[1];
        $this->leggiXref($this->ultimoStartxref);
        if (isset($this->trailer['Encrypt'])) {
            throw new RuntimeException('PDF cifrato');
        }
        $root = $this->numero($this->trailer['Root'][0] ?? '');
        $campiRoot = $this->campi($this->corpo($root));
        $catena = $this->percorsoPrimaPagina($this->numero($campiRoot['Pages'][0] ?? ''));
        [$numPagina, $corpoPagina, $campiPagina] = $catena[count($catena) - 1];

        foreach ($catena as [, , $c]) {
            if (isset($c['Rotate']) && ((int) $c['Rotate'][0]) % 360 !== 0) {
                throw new RuntimeException('pagina ruotata');
            }
        }
        $box = $this->scatola($catena);

        $size = (int) preg_replace('/\D/', '', $this->trailer['Size'][0] ?? '0');
        $prossimo = max($size, ($this->xref ? max(array_keys($this->xref)) : 0) + 1);
        $numAp = $prossimo;
        $numAnnot = $prossimo + 1;

        // La stampigliatura: una fascia a tutta larghezza in testa alla pagina, bordo e testo rossi, testo al centro
        $larghezza = $box[2] - $box[0] - 2 * self::MARGINE;
        $altezza = self::ALTEZZA_FASCIA;
        $x1 = $box[0] + self::MARGINE;
        $y2 = $box[3] - self::MARGINE;
        $rect = sprintf('[%s %s %s %s]', $this->n($x1), $this->n($y2 - $altezza), $this->n($x1 + $larghezza), $this->n($y2));
        $win = $this->winAnsi($testo);
        $xTesto = max(4.0, ($larghezza - self::larghezza($testo)) / 2);
        $contenuto = sprintf(
            "q
1 0 0 RG
0.75 w
0.375 0.375 %s %s re S
1 0 0 rg
BT
/F1 %s Tf
%s %s Td
(%s) Tj
ET
Q",
            $this->n($larghezza - 0.75),
            $this->n($altezza - 0.75),
            $this->n(self::CORPO),
            $this->n($xTesto),
            $this->n(($altezza - self::CORPO * 0.72) / 2),
            $this->escapaStringa($win)
        );
        $ap = sprintf(
            "<< /Type /XObject /Subtype /Form /FormType 1 /BBox [0 0 %s %s] /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >> >> >> /Length %d >>
stream
%s
endstream",
            $this->n($larghezza),
            $this->n($altezza),
            strlen($contenuto),
            $contenuto
        );
        $genPagina = $this->xref[$numPagina]['t'] === 1 ? $this->xref[$numPagina]['b'] : 0;
        $annot = sprintf(
            '<< /Type /Annot /Subtype /Stamp /Name /Segnatura /Rect %s /F 4 /P %d %d R /T %s /Contents %s /AP << /N %d 0 R >> >>',
            $rect,
            $numPagina,
            $genPagina,
            $this->stringaUtf16($this->autore),
            $this->stringaUtf16($testo),
            $numAp
        );

        // La pagina (o l'elenco delle sue annotazioni, se è un oggetto a parte) con il riferimento al timbro
        $nuovi = [$numAp => [0, $ap], $numAnnot => [0, $annot]];
        $rifAnnot = $numAnnot . ' 0 R';
        if (isset($campiPagina['Annots'])) {
            [$raw, $da, $a] = $campiPagina['Annots'];
            if ($raw !== '' && $raw[0] === '[') {
                $nuovoRaw = substr($raw, 0, -1) . ' ' . $rifAnnot . ']';
                $nuovi[$numPagina] = [$genPagina, substr($corpoPagina, 0, $da) . $nuovoRaw . substr($corpoPagina, $a)];
            } elseif (($numElenco = $this->numero($raw)) > 0 && ($corpoElenco = $this->corpo($numElenco)) !== '' && $corpoElenco[0] === '[') {
                $genElenco = $this->xref[$numElenco]['t'] === 1 ? $this->xref[$numElenco]['b'] : 0;
                $nuovi[$numElenco] = [$genElenco, substr($corpoElenco, 0, -1) . ' ' . $rifAnnot . ']'];
            } else {
                $nuovi[$numPagina] = [$genPagina, substr($corpoPagina, 0, $da) . '[' . $rifAnnot . ']' . substr($corpoPagina, $a)];
            }
        } else {
            $nuovi[$numPagina] = [$genPagina, substr(rtrim($corpoPagina), 0, -2) . ' /Annots [' . $rifAnnot . '] >>'];
        }

        return $this->scriviAggiornamento($nuovi, $prossimo + 2);
    }

    /**
     * @param array<int, array{0: int, 1: string}> $nuovi numero oggetto => [generazione, testo]
     */
    private function scriviAggiornamento(array $nuovi, int $primoLibero): string
    {
        $out = $this->pdf;
        if (!str_ends_with($out, "\n")) {
            $out .= "\n";
        }
        ksort($nuovi);
        $offset = [];
        foreach ($nuovi as $num => [$gen, $corpo]) {
            $offset[$num] = [strlen($out), $gen];
            $out .= $num . ' ' . $gen . " obj\n" . $corpo . "\nendobj\n";
        }
        $xrefOffset = strlen($out);
        $extra = '';
        foreach (['Info', 'ID'] as $k) {
            if (isset($this->trailer[$k])) {
                $extra .= ' /' . $k . ' ' . $this->trailer[$k][0];
            }
        }
        $root = $this->trailer['Root'][0];
        if ($this->xrefFlusso) {
            $numXref = $primoLibero;
            $offset[$numXref] = [$xrefOffset, 0];
            $righe = '';
            foreach ($offset as [$o, $g]) {
                $righe .= chr(1) . pack('N', $o) . pack('n', $g);
            }
            $index = [];
            foreach (array_keys($offset) as $num) {
                if ($index && $index[count($index) - 1][0] + $index[count($index) - 1][1] === $num) {
                    $index[count($index) - 1][1]++;
                } else {
                    $index[] = [$num, 1];
                }
            }
            $idx = implode(' ', array_map(static fn (array $r): string => $r[0] . ' ' . $r[1], $index));
            $out .= $numXref . " 0 obj\n<< /Type /XRef /Size " . ($numXref + 1) . ' /W [1 4 2] /Index [' . $idx . '] /Root ' . $root . $extra
                . ' /Prev ' . $this->ultimoStartxref . ' /Length ' . strlen($righe) . " >>\nstream\n" . $righe . "\nendstream\nendobj\n";
        } else {
            $out .= "xref\n";
            $nums = array_keys($offset);
            $i = 0;
            while ($i < count($nums)) {
                $j = $i;
                while ($j + 1 < count($nums) && $nums[$j + 1] === $nums[$j] + 1) {
                    $j++;
                }
                $out .= $nums[$i] . ' ' . ($j - $i + 1) . "\n";
                for ($k = $i; $k <= $j; $k++) {
                    $out .= sprintf("%010d %05d n \n", $offset[$nums[$k]][0], $offset[$nums[$k]][1]);
                }
                $i = $j + 1;
            }
            $out .= "trailer\n<< /Size " . $primoLibero . ' /Root ' . $root . $extra . ' /Prev ' . $this->ultimoStartxref . " >>\n";
        }

        return $out . "startxref\n" . $xrefOffset . "\n%%EOF\n";
    }

    // ── Lettura della struttura ────────────────────────────────────────────

    private function leggiXref(int $inizio): void
    {
        $daVisitare = [$inizio];
        $visti = [];
        $primo = true;
        while ($daVisitare) {
            $off = array_shift($daVisitare);
            if (isset($visti[$off]) || $off < 0 || $off >= strlen($this->pdf)) {
                continue;
            }
            $visti[$off] = true;
            $tr = $this->leggiSezione($off, $primo);
            if ($primo) {
                $this->trailer = $tr;
                $primo = false;
            }
            $prossimi = [];
            if (isset($tr['XRefStm'])) {
                $prossimi[] = (int) $tr['XRefStm'][0];
            }
            if (isset($tr['Prev'])) {
                $prossimi[] = (int) $tr['Prev'][0];
            }
            $daVisitare = array_merge($prossimi, $daVisitare);
        }
        if (!isset($this->trailer['Root'])) {
            throw new RuntimeException('manca Root');
        }
    }

    /**
     * Una sezione xref (tabella o flusso): aggiunge le voci non ancora note (vale la più recente) e ritorna i campi del trailer.
     *
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    private function leggiSezione(int $off, bool $primo): array
    {
        $pdf = $this->pdf;
        $off += strspn($pdf, " \t\r\n\f\0", $off);
        if (preg_match('/\Gxref\b/', $pdf, $m, 0, $off)) {
            $p = $off + 4;
            while (true) {
                $p += strspn($pdf, " \t\r\n\f\0", $p);
                if (substr($pdf, $p, 7) === 'trailer') {
                    $p += 7;
                    break;
                }
                if (!preg_match('/\G(\d+)\s+(\d+)/', $pdf, $h, 0, $p)) {
                    throw new RuntimeException('tabella xref non leggibile');
                }
                $p += strlen($h[0]);
                $inizio = (int) $h[1];
                for ($k = 0; $k < (int) $h[2]; $k++) {
                    if (!preg_match('/\G\s*(\d{10})\s+(\d{5})\s+([nf])/', $pdf, $e, 0, $p)) {
                        throw new RuntimeException('voce xref non leggibile');
                    }
                    $p += strlen($e[0]);
                    $this->voce($inizio + $k, $e[3] === 'n' ? ['t' => 1, 'a' => (int) $e[1], 'b' => (int) $e[2]] : ['t' => 0, 'a' => 0, 'b' => 0]);
                }
            }
            [$raw] = $this->leggiValore($pdf, $p);

            return $this->campi($raw);
        }
        // Flusso xref
        if (!preg_match('/\G(\d+)\s+(\d+)\s+obj\b/', $pdf, $m, 0, $off)) {
            throw new RuntimeException('xref non trovata');
        }
        [$raw, $dopo] = $this->leggiValore($pdf, $off + strlen($m[0]));
        $c = $this->campi($raw);
        $dati = $this->datiFlusso($c, $dopo);
        if ($primo) {
            $this->xrefFlusso = true;
        }
        $w = array_map('intval', preg_split('/\s+/', trim($c['W'][0] ?? '', '[] ')) ?: []);
        if (count($w) !== 3) {
            throw new RuntimeException('W non valido');
        }
        $sizeS = (int) ($c['Size'][0] ?? 0);
        $indice = isset($c['Index']) ? array_map('intval', preg_split('/\s+/', trim($c['Index'][0], '[] ')) ?: []) : [0, $sizeS];
        $riga = $w[0] + $w[1] + $w[2];
        $p = 0;
        $leggi = static function (string $d, int $da, int $n): int {
            $v = 0;
            for ($i = 0; $i < $n; $i++) {
                $v = ($v << 8) | ord($d[$da + $i]);
            }

            return $v;
        };
        for ($s = 0; $s + 1 < count($indice); $s += 2) {
            for ($k = 0; $k < $indice[$s + 1]; $k++) {
                if ($p + $riga > strlen($dati)) {
                    break 2;
                }
                $tipo = $w[0] > 0 ? $leggi($dati, $p, $w[0]) : 1;
                $f2 = $leggi($dati, $p + $w[0], $w[1]);
                $f3 = $leggi($dati, $p + $w[0] + $w[1], $w[2]);
                $p += $riga;
                $num = $indice[$s] + $k;
                $this->voce($num, match ($tipo) {
                    1 => ['t' => 1, 'a' => $f2, 'b' => $f3],
                    2 => ['t' => 2, 'a' => $f2, 'b' => $f3],
                    default => ['t' => 0, 'a' => 0, 'b' => 0],
                });
            }
        }

        return $c;
    }

    /** @param array{t: int, a: int, b: int} $v */
    private function voce(int $num, array $v): void
    {
        if (!isset($this->xref[$num])) {
            $this->xref[$num] = $v;
        }
    }

    /**
     * Da una pagina radice al primo foglio: l'elenco degli oggetti dell'albero (il primo è il nodo di partenza, l'ultimo la pagina).
     *
     * @return list<array{0: int, 1: string, 2: array<string, array{0: string, 1: int, 2: int}>}>
     */
    private function percorsoPrimaPagina(int $num): array
    {
        $catena = [];
        for ($livello = 0; $livello < 30; $livello++) {
            $corpo = $this->corpo($num);
            $c = $this->campi($corpo);
            $catena[] = [$num, $corpo, $c];
            $tipo = trim($c['Type'][0] ?? '');
            if ($tipo === '/Page' || !isset($c['Kids'])) {
                return $catena;
            }
            if (!preg_match('/(\d+)\s+\d+\s+R/', $c['Kids'][0], $k)) {
                throw new RuntimeException('albero delle pagine vuoto');
            }
            $num = (int) $k[1];
        }
        throw new RuntimeException('albero delle pagine troppo profondo');
    }

    /**
     * @param list<array{0: int, 1: string, 2: array<string, array{0: string, 1: int, 2: int}>}> $catena
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function scatola(array $catena): array
    {
        foreach (['CropBox', 'MediaBox'] as $chiave) {
            for ($i = count($catena) - 1; $i >= 0; $i--) {
                $c = $catena[$i][2];
                if (isset($c[$chiave]) && preg_match_all('/-?\d+(?:\.\d+)?/', $c[$chiave][0], $mm) && count($mm[0]) === 4) {
                    $v = array_map('floatval', $mm[0]);

                    return [min($v[0], $v[2]), min($v[1], $v[3]), max($v[0], $v[2]), max($v[1], $v[3])];
                }
            }
        }

        return [0.0, 0.0, 595.0, 842.0];
    }

    // ── Oggetti ────────────────────────────────────────────────────────────

    /** Il corpo dell'oggetto (dizionario, elenco o altro valore), senza il flusso. */
    private function corpo(int $num): string
    {
        $e = $this->xref[$num] ?? null;
        if ($e === null || $e['t'] === 0) {
            throw new RuntimeException("oggetto $num mancante");
        }
        if ($e['t'] === 1) {
            if (!preg_match('/\G\s*(\d+)\s+(\d+)\s+obj\b/', $this->pdf, $m, 0, $e['a']) || (int) $m[1] !== $num) {
                throw new RuntimeException("oggetto $num non trovato");
            }

            return $this->leggiValore($this->pdf, $e['a'] + strlen($m[0]))[0];
        }
        // Dentro un flusso di oggetti compressi
        $dati = $this->datiObjStm($e['a']);
        [$n, $first] = $this->intestazioneObjStm($e['a']);
        preg_match_all('/\d+/', substr($dati, 0, $first), $mm);
        $voci = $mm[0];
        $posizione = null;
        for ($i = 0; $i < $n; $i++) {
            if ((int) ($voci[2 * $i] ?? -1) === $num) {
                $posizione = $i;
                break;
            }
        }
        if ($posizione === null) {
            throw new RuntimeException("oggetto $num non nel flusso");
        }

        return $this->leggiValore($dati, $first + (int) $voci[2 * $posizione + 1])[0];
    }

    private function datiObjStm(int $stm): string
    {
        if (!isset($this->cacheStm[$stm])) {
            $e = $this->xref[$stm] ?? null;
            if ($e === null || $e['t'] !== 1 || !preg_match('/\G\s*(\d+)\s+(\d+)\s+obj\b/', $this->pdf, $m, 0, $e['a'])) {
                throw new RuntimeException("flusso $stm non trovato");
            }
            [$raw, $dopo] = $this->leggiValore($this->pdf, $e['a'] + strlen($m[0]));
            $this->cacheStm[$stm] = $this->datiFlusso($this->campi($raw), $dopo);
        }

        return $this->cacheStm[$stm];
    }

    /** @return array{0: int, 1: int} numero di oggetti e posizione del primo */
    private function intestazioneObjStm(int $stm): array
    {
        $e = $this->xref[$stm];
        preg_match('/\G\s*(\d+)\s+(\d+)\s+obj\b/', $this->pdf, $m, 0, $e['a']);
        $c = $this->campi($this->leggiValore($this->pdf, $e['a'] + strlen($m[0]))[0]);

        return [(int) ($c['N'][0] ?? 0), (int) ($c['First'][0] ?? 0)];
    }

    /**
     * I dati (decodificati) del flusso che segue il dizionario.
     *
     * @param array<string, array{0: string, 1: int, 2: int}> $c
     */
    private function datiFlusso(array $c, int $dopoDizionario): string
    {
        if (!preg_match('/\G\s*stream(\r\n|\n|\r)/', $this->pdf, $m, 0, $dopoDizionario)) {
            throw new RuntimeException('flusso non trovato');
        }
        $ini = $dopoDizionario + strlen($m[0]);
        $len = isset($c['Length']) && ctype_digit(trim($c['Length'][0])) ? (int) trim($c['Length'][0]) : -1;
        if ($len >= 0 && preg_match('/\G\s*endstream/', $this->pdf, $x, 0, $ini + $len)) {
            $dati = substr($this->pdf, $ini, $len);
        } else {
            $f = strpos($this->pdf, 'endstream', $ini);
            if ($f === false) {
                throw new RuntimeException('endstream mancante');
            }
            $dati = (string) preg_replace('/(\r\n|\n|\r)\z/', '', substr($this->pdf, $ini, $f - $ini), 1);
        }
        $filtro = trim($c['Filter'][0] ?? '', '[] ');
        if ($filtro === '') {
            return $dati;
        }
        if ($filtro !== '/FlateDecode' && $filtro !== '/Fl') {
            throw new RuntimeException('filtro non gestito');
        }
        $dec = @gzuncompress($dati);
        if ($dec === false) {
            $dec = @gzinflate(substr($dati, 2));
        }
        if ($dec === false) {
            throw new RuntimeException('flusso non decomprimibile');
        }
        $parametri = isset($c['DecodeParms']) ? $this->campi((string) (preg_match('/<<.*>>/s', $c['DecodeParms'][0], $dm) ? $dm[0] : '<<>>')) : [];
        $predittore = (int) ($parametri['Predictor'][0] ?? 1);
        if ($predittore >= 10) {
            $dec = $this->togliPredittorePng($dec, (int) ($parametri['Columns'][0] ?? 1), (int) ($parametri['Colors'][0] ?? 1), (int) ($parametri['BitsPerComponent'][0] ?? 8));
        } elseif ($predittore > 1) {
            throw new RuntimeException('predittore non gestito');
        }

        return $dec;
    }

    private function togliPredittorePng(string $d, int $colonne, int $colori, int $bit): string
    {
        $bpp = max(1, intdiv($colori * $bit, 8));
        $riga = intdiv($colonne * $colori * $bit + 7, 8);
        $righe = intdiv(strlen($d), $riga + 1);
        $out = '';
        $prec = str_repeat("\0", $riga);
        for ($r = 0; $r < $righe; $r++) {
            $tipo = ord($d[$r * ($riga + 1)]);
            $x = substr($d, $r * ($riga + 1) + 1, $riga);
            $cur = '';
            for ($i = 0; $i < $riga; $i++) {
                $a = $i >= $bpp ? ord($cur[$i - $bpp]) : 0;
                $b = ord($prec[$i]);
                $c = $i >= $bpp ? ord($prec[$i - $bpp]) : 0;
                $v = ord($x[$i]);
                $cur .= chr(match ($tipo) {
                    0 => $v,
                    1 => ($v + $a) & 255,
                    2 => ($v + $b) & 255,
                    3 => ($v + intdiv($a + $b, 2)) & 255,
                    4 => ($v + $this->paeth($a, $b, $c)) & 255,
                    default => throw new RuntimeException('filtro PNG sconosciuto'),
                });
            }
            $out .= $cur;
            $prec = $cur;
        }

        return $out;
    }

    private function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
    }

    // ── Analisi dei valori PDF ─────────────────────────────────────────────

    /**
     * Legge un valore (dizionario, elenco, stringa, nome, riferimento, numero) a partire da $p.
     *
     * @return array{0: string, 1: int} il testo del valore e la posizione dopo di esso
     */
    private function leggiValore(string $s, int $p): array
    {
        $n = strlen($s);
        $p += strspn($s, " \t\r\n\f\0", $p);
        if ($p >= $n) {
            throw new RuntimeException('valore mancante');
        }
        $c = $s[$p];
        if (($c === '<' && ($s[$p + 1] ?? '') === '<') || $c === '[') {
            $fine = $this->fineBilanciata($s, $p);

            return [substr($s, $p, $fine - $p), $fine];
        }
        if ($c === '(') {
            $fine = $this->fineStringa($s, $p);

            return [substr($s, $p, $fine - $p), $fine];
        }
        if ($c === '<') {
            $f = strpos($s, '>', $p);
            $fine = $f === false ? $n : $f + 1;

            return [substr($s, $p, $fine - $p), $fine];
        }
        if (preg_match('/\G(?:\d+\s+\d+\s+R(?![A-Za-z0-9])|\/[^\s\/<>\[\]()%]*|[^\s\/<>\[\]()%]+)/', $s, $m, 0, $p)) {
            return [$m[0], $p + strlen($m[0])];
        }
        throw new RuntimeException('valore non riconosciuto');
    }

    private function fineBilanciata(string $s, int $p): int
    {
        $n = strlen($s);
        $profondita = 0;
        for ($i = $p; $i < $n;) {
            $c = $s[$i];
            if ($c === '(') {
                $i = $this->fineStringa($s, $i);
                continue;
            }
            if ($c === '<') {
                if (($s[$i + 1] ?? '') === '<') {
                    $profondita++;
                    $i += 2;
                } else {
                    $f = strpos($s, '>', $i);
                    $i = $f === false ? $n : $f + 1;
                }
                continue;
            }
            if ($c === '>' && ($s[$i + 1] ?? '') === '>') {
                $profondita--;
                $i += 2;
                if ($profondita === 0) {
                    return $i;
                }
                continue;
            }
            if ($c === '[') {
                $profondita++;
            } elseif ($c === ']') {
                $profondita--;
                if ($profondita === 0) {
                    return $i + 1;
                }
            } elseif ($c === '%') {
                $f = strcspn($s, "\r\n", $i);
                $i += max(1, $f);
                continue;
            }
            $i++;
        }
        throw new RuntimeException('parentesi non chiusa');
    }

    private function fineStringa(string $s, int $p): int
    {
        $n = strlen($s);
        $profondita = 0;
        for ($i = $p; $i < $n; $i++) {
            $c = $s[$i];
            if ($c === '\\') {
                $i++;
            } elseif ($c === '(') {
                $profondita++;
            } elseif ($c === ')') {
                $profondita--;
                if ($profondita === 0) {
                    return $i + 1;
                }
            }
        }
        throw new RuntimeException('stringa non chiusa');
    }

    /**
     * I campi di primo livello di un dizionario: nome => [testo del valore, inizio, fine] (posizioni dentro $dict).
     *
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    private function campi(string $dict): array
    {
        $out = [];
        $dict = ltrim($dict);
        if (strncmp($dict, '<<', 2) !== 0) {
            return $out;
        }
        $n = strlen($dict);
        $p = 2;
        while ($p < $n) {
            $p += strspn($dict, " \t\r\n\f\0", $p);
            if (substr($dict, $p, 2) === '>>' || $p >= $n) {
                break;
            }
            if ($dict[$p] === '%') {
                $p += max(1, strcspn($dict, "\r\n", $p));
                continue;
            }
            if (!preg_match('/\G\/([^\s\/<>\[\]()%]*)/', $dict, $m, 0, $p)) {
                break;
            }
            $p += strlen($m[0]);
            $p += strspn($dict, " \t\r\n\f\0", $p);
            $inizio = $p;
            [$raw, $fine] = $this->leggiValore($dict, $p);
            $out[$m[1]] = [$raw, $inizio, $fine];
            $p = $fine;
        }

        return $out;
    }

    private function numero(string $riferimento): int
    {
        if (!preg_match('/^\s*(\d+)\s+\d+\s+R/', $riferimento, $m)) {
            throw new RuntimeException('riferimento non valido');
        }

        return (int) $m[1];
    }

    // ── Scrittura ──────────────────────────────────────────────────────────

    private function n(float $v): string
    {
        $s = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');

        return $s === '' || $s === '-0' ? '0' : $s;
    }

    private function winAnsi(string $t): string
    {
        $w = @mb_convert_encoding($t, 'Windows-1252', 'UTF-8');

        return is_string($w) ? $w : $t;
    }

    private function escapaStringa(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r', "\n" => '\\n']);
    }

    /** Stringa di testo PDF in UTF-16 con BOM, scritta in esadecimale. */
    private function stringaUtf16(string $t): string
    {
        return '<FEFF' . strtoupper(bin2hex((string) mb_convert_encoding($t, 'UTF-16BE', 'UTF-8'))) . '>';
    }
}
