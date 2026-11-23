<?php

declare(strict_types=1);

namespace Dodaroe\PdfFirme;

/** Firme digitali presenti in un PDF (PAdES: dizionario /Sig con /ByteRange dentro il file; un .p7m CAdES non è un PDF e non ne ha). */
interface VerificaFirme
{
    /**
     * @return list<array<string, mixed>> una voce per firma (subfilter, nome, data, valida_struttura, copre_tutto…); [] se il file non è un PDF firmato
     */
    public function firmePades(string $pdf): array;
}
