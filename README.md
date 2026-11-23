# php-pdf-firme

Due cose che in PHP si fanno male o a pagamento, in **PHP puro e senza dipendenze**:

1. **leggere le firme digitali PAdES** che stanno dentro un PDF e dire se coprono ancora tutto il file;
2. **apporre la segnatura di protocollo** su un PDF **già firmato**, senza invalidare le firme.

Il secondo punto è quello che non si trova in giro. La segnatura non riscrive il PDF: viene aggiunta in coda
come **aggiornamento incrementale** (nuovi oggetti e nuova tabella dei riferimenti con `/Prev`), quindi i byte
che la firma copriva **restano identici byte per byte** e i lettori di PDF continuano a mostrare la firma come
valida. L'originale non si tocca: il metodo ritorna una copia.

Requisiti: **PHP 8.1+**. Nessun pacchetto esterno. `zlib` (compilata in PHP quasi sempre) serve per i PDF con
gli oggetti compressi, cioè la maggior parte di quelli di oggi.

## Installazione

Con Composer:

```json
{
    "require": { "dodaroe/php-pdf-firme": "*" }
}
```

Senza Composer — e questo è il motivo per cui il pacchetto esiste, perché molti server pubblici sono così:

```php
require '/percorso/php-pdf-firme/autoload.php';
```

## Le firme dentro un PDF

```php
use Dodaroe\PdfFirme\FirmePades;

$firme = (new FirmePades())->firmePades(file_get_contents('contratto.pdf'));

if ($firme === []) {
    echo "Il file non risulta firmato in PAdES.\n";
}

foreach ($firme as $f) {
    echo $f['nome'], ' — ', $f['subfilter'], ' — ', $f['data'], "\n";
    if (!$f['valida_struttura']) {
        echo "  la firma non è integra\n";
    }
    if (!$f['copre_tutto']) {
        echo "  il PDF è stato modificato dopo la firma\n";
    }
}
```

Per ogni firma trovata: `subfilter` (`ETSI.CAdES.detached`, `adbe.pkcs7.detached`…), `nome` e `data` del
firmatario come li ha scritti il programma di firma, `fine` (ultimo byte coperto), `valida_struttura` e
`copre_tutto`. `copre_tutto` è il controllo che conta nella pratica: distingue «firmato» da «firmato e poi
risalvato», che è l'errore che fa la gente.

`VerificaFirme` è l'interfaccia: nelle tue prove passi un'implementazione finta e non ti servono PDF veri.

## La segnatura di protocollo

```php
use Dodaroe\PdfFirme\SegnaturaPdf;

$originale = file_get_contents('convenzione_firmata.pdf');
$timbrato = (new SegnaturaPdf('Comune di Prova'))->applica($originale, 'Prot. n. 1052 del 15/11/2026');

if ($timbrato === null) {
    // non si è potuto timbrare: si usa il file com'è
    $timbrato = $originale;
}
file_put_contents('convenzione_protocollata.pdf', $timbrato);
```

Il testo finisce in una fascia a tutta larghezza in testa alla prima pagina, come fanno i sistemi di
protocollo. L'argomento del costruttore è il nome che i lettori di PDF mostrano come autore del timbro.
Conserva **l'originale** come documento di protocollo e dai la copia timbrata a chi deve leggerla.

`applica()` ritorna `null`, senza lanciare eccezioni, quando non può: PDF cifrato, pagina ruotata, struttura
non leggibile, testo vuoto. Chi chiama decide cosa fare.

## Prove

```bash
php tests/esegui.php
```

24 prove, nessuna dipendenza, nessun PDF di esempio da portarsi dietro: i file di prova si costruiscono byte
per byte, comprese le tabelle dei riferimenti classiche e quelle a flusso.

## Cosa questo pacchetto non fa

- **Non firma.** Non crea firme, non ha niente a che fare con smart card, HSM o firma remota: legge le firme
  che ci sono già.
- **Non verifica i certificati.** Dice se la firma è strutturalmente integra e se copre tutto il file, non se
  il certificato è valido, revocato o di chi dice di essere. Per quello serve una catena di certificati e una
  verifica OCSP/CRL, che qui non c'è.
- **Non legge i .p7m** (CAdES): un `.p7m` non è un PDF.
- **Non genera PDF** da zero e non converte niente in PDF.
- **Non tratta i PDF cifrati** né le pagine ruotate: in quei casi la segnatura ritorna `null`.
- **L'assistenza non è garantita.** È codice usato in produzione da un portale universitario e pubblicato
  perché possa servire ad altri, non un prodotto con un supporto dietro. Se ti serve una garanzia, fai una
  copia tua e mantienila.

## Licenza

**AGPL-3.0-or-later**: vedi il file [LICENSE](LICENSE). Vuol dire che se usi questo codice in un programma
che offri ad altri, anche solo via rete, quel programma va pubblicato con la stessa licenza.
