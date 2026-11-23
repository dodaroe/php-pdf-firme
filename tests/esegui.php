<?php

declare(strict_types=1);

// Prove del pacchetto, senza Composer e senza PHPUnit: `php tests/esegui.php`.
// I PDF di prova si costruiscono qui a mano, byte per byte: nessun file di esempio da portarsi dietro.
require __DIR__ . '/../autoload.php';

use Dodaroe\PdfFirme\FirmePades;
use Dodaroe\PdfFirme\SegnaturaPdf;

$ok = 0;
$ko = 0;
function prova(bool $condizione, string $nome): void
{
    global $ok, $ko;
    if ($condizione) {
        $ok++;
        echo "  ok  $nome\n";
    } else {
        $ko++;
        echo "  KO  $nome\n";
    }
}
function sezione(string $t): void
{
    echo "\n== $t ==\n";
}

const TESTO = 'Prot. n. 0001052 del 15/11/2026';

/** Un PDF classico minimo (tabella xref, trailer), con una pagina e una riga di testo. */
function pdfClassico(): string
{
    $cont = 'BT /F1 12 Tf 72 700 Td (Lettera di incarico) Tj ET';
    $oggetti = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R >>',
        4 => '<< /Length ' . strlen($cont) . " >>\nstream\n" . $cont . "\nendstream",
    ];
    $pdf = "%PDF-1.4\n";
    $off = [];
    foreach ($oggetti as $n => $corpo) {
        $off[$n] = strlen($pdf);
        $pdf .= "$n 0 obj\n$corpo\nendobj\n";
    }
    $x = strlen($pdf);
    $pdf .= "xref\n0 " . (count($oggetti) + 1) . "\n0000000000 65535 f \n";
    foreach ($off as $o) {
        $pdf .= sprintf("%010d 00000 n \n", $o);
    }

    return $pdf . "trailer\n<< /Size " . (count($oggetti) + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
}

/** Un PDF con la pagina dentro un flusso di oggetti compressi (ObjStm) e la tabella dei riferimenti come flusso (xref stream). */
function pdfConFlussi(): string
{
    $testi = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 5 0 R >>',
    ];
    $intest = '';
    $corpo = '';
    foreach ($testi as $k => $t) {
        $intest .= ($k + 1) . ' ' . strlen($corpo) . ' ';
        $corpo .= $t . "\n";
    }
    $dati = gzcompress($intest . $corpo);
    $pdf = "%PDF-1.7\n";
    $off4 = strlen($pdf);
    $pdf .= "4 0 obj\n<< /Type /ObjStm /N 3 /First " . strlen($intest) . ' /Filter /FlateDecode /Length ' . strlen($dati) . " >>\nstream\n" . $dati . "\nendstream\nendobj\n";
    $off5 = strlen($pdf);
    $cont = 'BT /F1 12 Tf 72 700 Td (Ciao) Tj ET';
    $pdf .= "5 0 obj\n<< /Length " . strlen($cont) . " >>\nstream\n" . $cont . "\nendstream\nendobj\n";
    $off6 = strlen($pdf);
    $righe = chr(0) . pack('N', 0) . pack('n', 65535)
        . chr(2) . pack('N', 4) . pack('n', 0)
        . chr(2) . pack('N', 4) . pack('n', 1)
        . chr(2) . pack('N', 4) . pack('n', 2)
        . chr(1) . pack('N', $off4) . pack('n', 0)
        . chr(1) . pack('N', $off5) . pack('n', 0)
        . chr(1) . pack('N', $off6) . pack('n', 0);
    $pdf .= "6 0 obj\n<< /Type /XRef /Size 7 /W [1 4 2] /Root 1 0 R /Length " . strlen($righe) . " >>\nstream\n" . $righe . "\nendstream\nendobj\n";

    return $pdf . "startxref\n" . $off6 . "\n%%EOF\n";
}

/** Un PDF finto con una firma: dizionario /Sig con /ByteRange; con $coda = false il tratto firmato lascia fuori gli ultimi 40 byte. */
function pdfFirmato(bool $coda = true): string
{
    $pdf = "%PDF-1.7\n1 0 obj\n<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /ETSI.CAdES.detached /Name (Mario Rossi) /M (D:20261006120000+02'00') /ByteRange [0 50 100 XXXX] >>\nendobj\n" . str_repeat('x', 60) . "\n%%EOF\n";

    return str_replace('XXXX', str_pad((string) (strlen($pdf) - 100 - ($coda ? 0 : 40)), 4, ' ', STR_PAD_LEFT), $pdf);
}

sezione('Firme PAdES dentro il PDF');

$f = new FirmePades();
prova($f->firmePades('PK file zip') === [] && $f->firmePades('') === [] && $f->firmePades("%PDF-1.7\nsenza firme\n%%EOF") === [], 'quello che non e un PDF firmato non ha firme');

$firme = $f->firmePades(pdfFirmato());
prova(count($firme) === 1, 'una firma trovata');
prova([$firme[0]['subfilter'], $firme[0]['nome'], $firme[0]['data']] === ['ETSI.CAdES.detached', 'Mario Rossi', '20261006120000'], 'tipo di firma, nome e data del firmatario');
prova($firme[0]['valida_struttura'] === true && $firme[0]['copre_tutto'] === true, 'la firma e strutturalmente valida e copre tutto il file');

$firme = $f->firmePades(pdfFirmato(false));
prova($firme[0]['copre_tutto'] === false, 'modifiche dopo la firma: il file prosegue oltre il tratto firmato');

$firme = $f->firmePades(pdfFirmato() . "\n\n");
prova($firme[0]['copre_tutto'] === true, 'copre_tutto non si lascia ingannare dagli spazi in coda');

sezione('Segnatura di protocollo sul PDF gia firmato');

$in = pdfClassico();
$out = (new SegnaturaPdf())->applica($in, TESTO);
prova($out !== null, 'il PDF classico si timbra');
prova(is_string($out) && strncmp($out, $in, strlen($in)) === 0, 'i byte di prima non cambiano (le firme che li coprono restano valide)');
$coda = is_string($out) ? substr($out, strlen($in)) : '';
prova(str_contains($coda, '/Subtype /Stamp') && str_contains($coda, '(' . TESTO . ') Tj'), 'in coda c\'e l\'annotazione timbro con il testo');
prova(str_contains($coda, '/Annots [') && str_contains($coda, '/Prev '), 'la pagina rimanda al timbro e la nuova tabella rimanda alla vecchia');
prova(is_string($out) && preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', $out, $m) === 1 && substr($out, (int) $m[1], 4) === 'xref', 'la nuova tabella parte dove dice startxref');

$in = pdfConFlussi();
$out = (new SegnaturaPdf())->applica($in, TESTO);
prova($out !== null && strncmp((string) $out, $in, strlen($in)) === 0, 'anche il PDF con oggetti compressi e xref a flusso si timbra');
prova(is_string($out) && str_contains(substr($out, strlen($in)), '/Type /XRef'), 'con la xref a flusso anche l\'aggiornamento usa un flusso');
prova(is_string($out) && (new SegnaturaPdf())->applica($out, 'Seconda segnatura') !== null, 'si puo timbrare di nuovo una copia gia timbrata');

$in = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 612 792] >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /Annots [4 0 R] >>\nendobj\n4 0 obj\n<< /Type /Annot /Subtype /Link /Rect [0 0 10 10] >>\nendobj\n";
$o = [];
foreach ([1, 2, 3, 4] as $n) {
    $o[$n] = strpos($in, $n . ' 0 obj');
}
$x = strlen($in);
$in .= "xref\n0 5\n0000000000 65535 f \n";
foreach ($o as $off) {
    $in .= sprintf("%010d 00000 n \n", $off);
}
$in .= "trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n" . $x . "\n%%EOF\n";
$out = (new SegnaturaPdf())->applica($in, TESTO);
prova(is_string($out) && preg_match('#/Annots \[4 0 R 6 0 R\]#', substr($out, strlen($in))) === 1, 'la pagina aveva gia le sue annotazioni: il timbro si aggiunge in fondo');
prova(is_string($out) && str_contains(substr($out, strlen($in)), '/Rect ['), 'la scatola della pagina ereditata dal nodo padre basta a posizionare il timbro');

sezione('Quello che non si timbra');

$s = new SegnaturaPdf();
prova($s->applica('non un pdf', TESTO) === null, 'quello che non e un PDF');
prova($s->applica(pdfClassico(), '  ') === null, 'senza testo non c\'e niente da scrivere');
prova($s->applica(str_replace('/Root', '/Encrypt 9 0 R /Root', pdfClassico()), TESTO) === null, 'i PDF cifrati non si toccano');
prova($s->applica("%PDF-1.4\nsenza struttura\n%%EOF", TESTO) === null, 'un PDF di cui non si legge la struttura');

sezione('Autore del timbro e larghezza del testo');

$conAutore = (new SegnaturaPdf('Comune di Prova'))->applica(pdfClassico(), TESTO);
$atteso = '/T <FEFF' . strtoupper(bin2hex(mb_convert_encoding('Comune di Prova', 'UTF-16BE', 'UTF-8'))) . '>';
prova(is_string($conAutore) && str_contains($conAutore, $atteso), 'l\'autore del timbro e quello passato al costruttore (annotazione /T)');
$predefinito = (new SegnaturaPdf())->applica(pdfClassico(), TESTO);
prova(is_string($predefinito) && !str_contains($predefinito, $atteso), 'senza autore resta quello predefinito');
prova(abs(SegnaturaPdf::larghezza('0', 11.0) - 6.116) < 0.01, 'larghezza di un carattere in Helvetica');
prova(SegnaturaPdf::larghezza(TESTO, 11.0) > SegnaturaPdf::larghezza('Prot.', 11.0), 'un testo piu lungo e piu largo');

echo "\n$ok prove riuscite, $ko fallite.\n";
exit($ko > 0 ? 1 : 0);
