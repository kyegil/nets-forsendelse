<?php
/**********************************************
Leiebase for Svartlamoen boligstiftelse
av Kay-Egil Hauan
Denne fila ble sist oppdatert 2016-02-23
**********************************************/

//	Forsendelse fra NETS

// En NETS-forsendelse i BBS-format har følgende faste egenskaper:
//	dataavsender (heltall):			Fra NETS: 8080. Til NETS: Avsenders Kundeenhet-ID
//	datamottaker:					Fra NETS: Avsenders Kundeenhet-ID. Til NETS: 8080
//	forsendelsesnummer (heltall):	Fra NETS: Løpenummer generert av NETS
//	forsendelsestype (heltall):		Forsendelsestype er alltid 0 på rotnivå
//	tjeneste (heltall):				NETS' tjenestekode er alltid 0 på forsendelsesnivå

//	Faste egenskaper per oppdrag:
//		Tjeneste (heltall):			Oppdragets tjenestenummer
//									9 = OCR konteringsdata
//									21 = avtalegiro
//									42 = efaktura
//		Oppdragsnummer (heltall):	Oppdragets oppdragsnr
//		Oppdragstype (heltall)		Oppdragstype vil variere innenfor hver tjeneste

// Alle oppdrag i en NetsForsendelse er stdclass-objekter
// Alle transaksjoner kan oppgis i stdClass-format eller som Giro-objekter
// Alle beløp oppgis i antall kroner, med maks to desimaler
// Alle datoer oppgis som DateTime-objekter


class NetsForsendelse {

public		$records = array();	// Alle records i forsendelsen, 
								// inkl start- og sluttrecord
public		$startrecord;		// Forsendelsens startrecord
public		$sluttrecord;		// Forsendelsens sluttrecord

public		$tjeneste;			// Nets' tjeneste
								// Denne er kanskje unødvendig på forsendelsesnivå
								// fordi den alltid vil være 0 ??
public		$forsendelsestype;	// Nets' forsendelsestype
public		$dataavsender;		// Dataavsender
public		$forsendelsesnummer;// Løpenummer generert av Nets
public		$datamottaker;		// Datamottaker
public		$antallRecords; 	// Antall records i følge sluttrecord
public		$antallTransaksjoner; // Antall transaksjoner dersom aktuelt
public		$sumBeløp;			// Sum kronebeløp dersom aktuelt
public		$dato; 				// Nets dato / oppgjørsdato / første forfallsdato
								//	oppgitt i felt 8 (posisjon 42-47) i sluttrecord 
public		$fildato; 			// Evt dato oppgitt i posisjon 32-39 i startrecord
public		$oppdrag = array();	// Alle oppdragene i forsendelsen

protected	$gyldig = false;	// Om forsendelsen er gyldig eller ikke 
protected	$peker;				// Intern peker for å angi posisjon i forsendelsen
								// Når denne er null befinner behandlingen seg
								// utenfor behandling.
protected	$feilkode;			// Feilkode
protected	$msg = "";			// Feilmelding 


// Constructor
/****************************************/
//	$records enten en filstreng, eller et array av record-strenger
//	--------------------------------------
public function __construct( $records = array() ) {
	if ( is_string( $records ) ) {
		$records = trim(stristr($records, "NY0"));
		$records = explode("\n", $records);
	}
	
	settype( $records, 'array' ); 
	$this->records = $records;
//	$this->records = array_map("trim", $records);
	
	$this->gyldig = $this->analyserBbsStreng();
}



// Når objektet omdannes til streng vises records-strengene
/****************************************/
//	--------------------------------------
//	retur:	(int) id-heltallet for objektet
public function __toString() {
	return implode("\n", $this->records);
}



// Formatering av numeriske verdier, høyrejustert
/****************************************/
//	--------------------------------------
//	retur:	(streng) Formatert verdi
public function _num( $verdi, $lengde, $fyll = "0" ) {
	$streng = mb_substr( $verdi, 0, $lengde, 'UTF-8' );
	$fyll = mb_substr( $fyll, 0, 1, 'UTF-8' );
	return str_repeat($fyll, $lengde - mb_strlen($streng, 'UTF-8') ) . $streng;
}



// Formatering av strengverdier, venstrejustert
/****************************************/
//	--------------------------------------
//	retur:	(streng) Formatert verdi
public function _str( $verdi, $lengde, $fyll = " " ) {
	$streng = mb_substr( $verdi, 0, $lengde, 'UTF-8' );
	$fyll = mb_substr( $fyll, 0, 1, 'UTF-8' );
	return $streng . str_repeat($fyll, $lengde - mb_strlen($streng, 'UTF-8') );
}




// Nummererer alle oppdragene i forsendelsen
/****************************************/
//	--------------------------------------
protected function _nummererOppdrag() {

	$this->antallOppdrag = 0;
	foreach( $this->oppdrag as $oppdrag ) {
		$this->antallOppdrag ++;
		if( !@$oppdrag->oppdragsnr ) {
			$oppdrag->oppdragsnr = $this->antallOppdrag;
		}
	}

}



// Lag startrecord for forsendelsen
/****************************************/
//	--------------------------------------
//	retur:	(boolsk) Suksess
protected function _skrivStartrecord() {
	$this->startrecord =
	"NY" /* (formatkode) */
	. "00" /* (tjenestekode) */
	. "00" /* (transaksjonstype) */
	. "10"  /* (recordtype) */
	. $this->_num( $this->dataavsender, 8)
	. $this->_num( $this->forsendelsesnummer, 7)
	. $this->_num( $this->datamottaker, 8)
	. date('dmY') /* (dato 8 tegn) */
	. $this->_num( $this->produksjon, 1)
	. str_repeat("0", 40);  /* (filler) */
	
	$this->records = array( $this->startrecord );
}



// Lag sluttrecord for forsendelsen
/****************************************/
//	--------------------------------------
protected function _skrivSluttrecord() {
	$this->sluttrecord =
	"NY" /* (formatkode) */
	. "00" /* (tjenestekode) */
	. "00" /* (transaksjonstype) */
	. "89"  /* (recordtype) */
	. $this->_num( @$this->antallTransaksjoner, 8)
	. $this->_num( $this->antallRecords, 8)
	. $this->_num( bcmul( $this->sumBeløp, 100, 0 ), 17)
	. ($this->dato ? $this->dato->format('dmy') : date('dmy')) /* (dato 6 tegn) */
	. str_repeat("0", 33);  /* (filler) */
	
	$this->records[] = $this->sluttrecord;
}



// Dra ut innholdet fra strengen
/****************************************/
//	--------------------------------------
//	retur:	(stdClass-objekt) Innholdet i oppdraget
public function analyserBbsStreng() {
	
	if( !isset( $this->records[0] ) or substr( $this->records[0], 6, 2 ) != "10" ) {
		$this->feilkode =	2;
		$this->msg =	"Fant ikke startrecord for forsendelsen.";
		return false;
	}
	$this->startrecord	= $this->records[0];
	$this->peker = '$this';

	$this->forsendelsestype	= (int)substr($this->startrecord, 4, 2);
	$this->dataavsender	= (int)substr($this->startrecord, 8, 8);
	$this->forsendelsesnummer = substr($this->startrecord, 16, 7);
	$this->datamottaker	= (int)substr($this->startrecord, 23, 8);
	$this->fildato		= substr($this->startrecord, 31, 8);
	if( (int)$this->fildato ) {
		$this->fildato = date_create_from_format(
			'dmY',
			$this->fildato
		);
	}
	else {
		$this->fildato = null;
	}
	$this->produksjon	= (bool)substr($this->startrecord, 39, 1);

	foreach( $this->records as $index => $record ) {

		// Alle records må begynne med 'NY'
		if(substr($record, 0, 2) != "NY") {
			$this->feilkode =	1;
			$this->msg .=	"Linje " . $index + 1 . " er ikke en gyldig record: {$record}";
			return false;
		}
		
		$recordtype = (int)substr($record, 6, 2);
		$tjeneste	= (int)substr($record, 2, 2);
		
		if ( method_exists($this, "bbsLesTjeneste{$tjeneste}") ) {
			if(
			!$this->{"bbsLesTjeneste{$tjeneste}"}( $record, $recordtype )
			) {
				return false;
			}
		}
		// Ved sluttrecord vil analysen avsluttes
		if($recordtype == 89) {
			$this->sluttrecord = $record;
			$this->antallTransaksjoner = (int)substr( $record, 8, 8 );
			$this->antallRecords = (int)substr( $record, 16, 8 );
			$this->sumBeløp	= bcdiv( (int)substr( $record, 24, 17 ), 100, 2);
			
			$datoformat = 'dmy';
			if( @$this->oppdrag[0]->oppdragstype == 94 ) {
				$datoformat = 'ymd';
			}
			
			$this->dato	= date_create_from_format(
				$datoformat,
				substr( $record, 41, 6 )
			);

			$this->peker = null;

			if(  $this->antallRecords  != count( $this->records ) ) {
				$this->msg = "Antall records (" . count( $this->records ) . ") stemmer ikke over ens med hva som er oppgitt i forsendelsens sluttrecord ({$this->antallRecords}).";
				return false;
			}

			// Sluttrecord er funnet og antall records stemmer.
			// Analysen avsluttes.
			return true;
		}	
	
	}
	
	
	// Alle records er gjennomgått, men sluttrecord er ikke funnet.
	$this->feilkode =	3;
	$this->msg =	"Fant ikke sluttrecord for oppdraget.";

	return false;
}



// Sjekk om innholdet faktisk er et gyldig oppdrag
/****************************************/
//	--------------------------------------
//	retur:	(boolsk) Gyldighet
public function feilmelding( $kode ) {
	$koder = array(
		0	=> "Mottak & Syntakskontroll av forsendelsen ok",
		1	=> "Forsendelse er ferdig prosessert hos NETS",
		40	=> "Faktura uten kobling til forbruker",
		54	=> "Forfallsdato har feil format",
		55	=> "Ugyldig forfallsdato",
		56	=> "Oppgitt beløp har feil format",
		57	=> "Oppgitt beløp er negativt",
		103	=> "Forfallsdato er angitt for langt frem i tid",
		145	=> "Kreditkontonummer har feil format",
		325	=> "KID er ugyldig",
		354	=> "For sent ankommet transaksjon",
		501	=> "Ugyldig kode i Summarytype (felt i eFakturareferanser 2)",
		502	=> "Kreditkontonummer er ugyldig",
		503	=> "Betalingskravet avvist pga. av manglende aktiv avtale",
		504	=> "EFAKTURAREFERANSE mangler/feil utfylt (felt i eFakturareferanser 2)",
		507	=> "Ugyldig ”Utsteders referansenummer i BBS”",
		524	=> "Ugyldig dato på inn-forsendelse",
		525	=> "Ugyldig oppdragsnummer/referanse på oppdrag/inn-forsendelsesnummer",
		526	=> "Testdata i produksjon",
		527	=> "Ugyldig mottaker på inn-forsendelse",
		529	=> "Duplikat inn-forsendelse",
		535	=> "Mangler forsendelse slutt",
		536	=> "Faktisk antall transaksjoner er ikke i henhold til antall transaksjoner angitt på sluttforsendelse",
		543	=> "Faktisk antall transaksjoner ikke i henhold ”Antall transaksjoner” fra slutt oppdrag record",
		549	=> "Mangler start oppdrag",
		551	=> "Mangler slutt oppdrag",
		552	=> "Ikke samsvar mellom oppgitt antall record'er og faktisk antall i forsendelsen",
		553	=> "Ugyldig recordtype i forsendelsen",
		555	=> "Ikke samsvar mellom oppgitt antall record'er og faktisk antall på oppdrag",
		556	=> "Startforsendelse eller sluttforsendelse eksisterer mer enn en gang i forsendelsen",
		557	=> "Feil antall spesifikasjonsrecorder",
		559	=> "Oppdragsnummer ikke i sekvens i forsendelse",
		560	=> "Unumeriske data i numeriske felter i startrecord forsendelse",
		561	=> "Unumeriske data i numeriske felter i sluttrecord forsendelse",
		562	=> "Unumeriske data i numeriske felter i startrecord oppdrag",
		563	=> "Unumeriske data i numeriske felter i sluttrecord oppdrag",
		564	=> "Feil recordlengde. Maks lengde i BBS Format er 80 tegn",
		565	=> "Mangler forsendelse start",
		566	=> "Transaksjonsnummer er ikke i sekvens",
		567	=> "Record med ugyldig transaksjonsnummer i en transaksjon",
		568	=> "Transaksjon er ikke komplett. Manglende eller overflødig record i en transaksjon."
	);
	if( isset( $koder[(int)$kode] ) ) {
		return $koder[(int)$kode];
	}
	else {
		return "Mottatt ukjent feilkode {$kode} fra Nets";
	}
}



//	Leser records i tjeneste 9:
//	OCR konteringsdata
//
//	Oppdraget har følgende egenskaper:
//	->antallRecords (heltall): 		Antall records inklusive start- og sluttrecords for oppdrag
//	->antallTransaksjoner (heltall): Antall transaksjoner i forsendelsen
//	->avtaleId (heltall):			Avtale-Id for oppdragskonto tildelt av Nets
//	->førsteOppgjørsdato (DateTime-objekt): Første oppgjørsdato i oppdraget
//	->oppdragskonto (heltall):		Avtalens bankkontonummer
//	->oppdragsnr (heltall):		Oppdragets løpenummer i forsendelsen
//	->oppdragstype (heltall):		Oppdragstype (alltid 0)
//	->oppgjørsdato (DateTime-objekt): Oppdragets oppgjørsdato
//	->sisteOppgjørsdato (DateTime-objekt): Siste oppgjørsdato i oppdraget
//	->tjeneste (heltall):			Tjenestenummeret er alltid 9
//	->transaksjoner (array av stdClass-objekter):	Transaksjoene i oppdraget

//		->arkivreferanse (heltall):			Arkivreferanse
//		->baxnr (heltall):					Baxnr ved trans-type 18, 19, 20 eller 21
//		->beløp (nummer):					Transaksjonsbeløpet i kr
//		->blankettnummer (nummer):			Giroens blankettnr
//		->dagkode (heltall): 				Datoen (d) transaksjonen er behandlet
//		->debetKonto (heltall): 			Betalers konto eller bankens interimskonto
//		->delavregningsnr (heltall):		Hvilken delavregning transaksjonen er avregnet
//		->fritekstmelding (streng):			Fritekstmelding fra betalingsterminalen ved type 20 og 21
//		->kid (streng):					KID-nummer brukt i transaksjonen
//		->kortutsteder (heltall): 			Inneholder kortutsteder for transaksjonstype 18, 19, 20 og 21
//		->løpenr (heltall):					Løpenummer innen delavregningen
//		->oppdragsdato (DateTime-objekt):	Dato for når oppdraget er levert bank eller datasentral
//		->oppgjørsdato (DateTime-objekt):	Dato for transaksjonen
//		->sentralId (heltall): 				De to første posisjonene i bankdatasentralnummeret som transaksjonen er overført til
//		->sesjonsnr (heltall):				Sesjonsnummer ved trans-type 18, 19, 20 eller 21
//		->transaksjonsnr (heltall):			Transaksjonens løpenummer i oppdraget
//		->transaksjonstype (heltall):		Transaksjonstype:
// 											Transaksjonstyper:
// 											10 Transaksjon fra giro belastet konto
// 											11 Transaksjon fra Faste Oppdrag
// 											12 Transaksjon fra Direkte Remittering
// 											13 Transaksjon fra BTG (Bedrifts Terminal Giro)
// 											14 Transaksjon fra SkrankeGiro
// 											15 Transaksjon fra AvtaleGiro
// 											16 Transaksjon fra TeleGiro
// 											17 Transaksjon fra Giro - betalt kontant
// 
// 											Transaksjon fra betalingsterminal og nettbetalinger:
// 											18 Reversering med KID
// 											19 Kjøp med KID
// 											20 Reversering med fritekst
// 											21 Kjøp med fritekst
//		->utbetaler (heltall): 				Utbetalers Avtale-Id ved Direkte remittering (trans.type 12)
/****************************************/
//	--------------------------------------
//	retur:	(boolsk) Suksessparameter
public function bbsLesTjeneste9( $record, $recordtype ) {
		
	switch( $recordtype ) {
	
	// Startrecord for oppdrag
	case 20:
		// Et nytt oppdrag legges til på nåværende nivå,
		// og pekeren flyttes inn i det nye oppdragsobjektet 
		$oppdrag = eval("return $this->peker;");
		settype( $oppdrag->oppdrag, 'array' );
		$this->peker .= "->oppdrag[ " . ($index = count( $oppdrag->oppdrag )) . " ]";
		settype( $oppdrag->oppdrag[ $index ], 'object' );
		$oppdrag = eval("return $this->peker;");
		
		$oppdrag->tjeneste		= 9;
		$oppdrag->oppdragstype	= (int)substr( $record, 4, 2 );
		$oppdrag->avtaleId		= (int)substr( $record, 8, 9 );
		$oppdrag->oppdragsnr	= (int)substr( $record, 17, 7 );
		$oppdrag->oppdragskonto	= (int)substr( $record, 24, 11 );
		$oppdrag->sumTransaksjoner	= 0;

		break;
		
	// Transaksjonsrecord beløpspost 1
	case 30:
		$oppdrag = eval("return $this->peker;");

		$transaksjonsnr	= intval(substr( $record, 8, 7 ));
		
		settype(
			$oppdrag->transaksjoner[$transaksjonsnr - 1],
			'object'
		);
		
		$transaksjon = $oppdrag
			->transaksjoner[$transaksjonsnr - 1];
		
		$transaksjon->transaksjonstype
			= (int)substr( $record, 4, 2 );
		$transaksjon->transaksjonsnr
			= $transaksjonsnr;
		$transaksjon->oppgjørsdato
			= date_create_from_format(
				'dmy',
				substr( $record, 15, 6 )
			);
		$transaksjon->sentralId
			= (int)substr( $record, 21, 2 );
		$transaksjon->dagkode
			= (int)substr( $record, 23, 2 );
		$transaksjon->delavregningsnr
			= (int)substr( $record, 25, 1 );
		$transaksjon->løpenr
			= (int)substr( $record, 26, 5 );
		$transaksjon->beløp
			= bcdiv(
				substr( $record, 31, 18 ),
				100,
				2
			);
		$transaksjon->kid
			= trim(substr( $record, 49, 25 ));
		$transaksjon->kortutsteder
			= (int)substr( $record, 74, 2 );
		$transaksjon->fritekstmelding = "";
		
		$oppdrag->sumTransaksjoner
			= bcadd(
				$oppdrag->sumTransaksjoner,
				bcdiv(substr( $record, 32, 17 ), 100, 2),
				2
			);
		
		break;

	// Transaksjonsrecord beløpspost 2
	case 31:
		$oppdrag = eval("return $this->peker;");

		$transaksjonsnr	= intval(substr( $record, 8, 7 ));
		
		$transaksjon = $oppdrag
			->transaksjoner[$transaksjonsnr - 1];

		$transaksjon->blankettnummer
			= (int)substr( $record, 15, 10 )
			? (int)substr( $record, 15, 10 )
			: "";
		
		if( $transaksjon->transaksjonstype == 12 ) {
			$transaksjon->utbetaler
				= (int)substr( $record, 25, 9 );
		}
		else if(
			$transaksjon->transaksjonstype >= 18 
			&& $transaksjon->transaksjonstype <= 21 
		) {
			$transaksjon->baxnr
				= (int)substr( $record, 25, 6 );
			$transaksjon->sesjonsnr
				= (int)substr( $record, 31, 3 );
		}
		else {
			$transaksjon->arkivreferanse
				= (int)substr( $record, 25, 9 );
		}
		
		$transaksjon->oppdragsdato
			= date_create_from_format(
				'dmy',
				substr( $record, 41, 6 )
			);
		$transaksjon->debetKonto
			= (int)substr( $record, 47, 11 )
			? substr( $record, 47, 11 )
			: "";
		
		break;

	// Transaksjonsrecord beløpspost 3
	//	kun ved transaksjonstypene 20 (Reversering med fritekst) og 21 (Kjøp med fritekst)
	case 32:
		$oppdrag = eval("return $this->peker;");

		$transaksjonsnr	= intval(substr( $record, 8, 7 ));
		
		$transaksjon = $oppdrag
			->transaksjoner[$transaksjonsnr - 1];

		$transaksjon->fritekstmelding
			= trim( substr( $record, 15, 40 ) );
		
		break;

	// Sluttrecord for oppdrag
	case 88:
		$oppdrag = eval("return $this->peker;");
	
		$oppdrag->antallTransaksjoner
			= (int)substr( $record, 8, 8 );
		$oppdrag->antallRecords
			= (int)substr( $record, 16, 8 );
		$oppdrag->sumBeløp
			= bcdiv(
				substr( $record, 24, 17 ),
				100,
				2
			);
		$oppdrag->oppgjørsdato
			= date_create_from_format(
				'dmy',
				substr( $record, 41, 6 )
			);
		$oppdrag->førsteOppgjørsdato
			= date_create_from_format(
				'dmy',
				substr( $record, 47, 6 )
			);
		$oppdrag->sisteOppgjørsdato
			= date_create_from_format(
				'dmy',
				substr( $record, 53, 6 )
			);


		if( $oppdrag->antallTransaksjoner != count( $oppdrag->transaksjoner ) ) {
			$this->msg = "Antall transaksjoner (" . count( $oppdrag->transaksjoner ) . ") stemmer ikke over ens med hva som er oppgitt i oppdragets sluttrecord ({$oppdrag->antallTransaksjoner}).";
			return false;
		}

		if( $oppdrag->sumBeløp != $oppdrag->sumTransaksjoner ) {
			$this->msg = "Summen av transaksjonene stemmer ikke over ens med hva som er oppgitt i oppdragets sluttrecord";
			$this->gyldig = false;
			return false;
		}
			
		// Flytt pekeren ett nivå tilbake idet oppdraget avsluttes
		$this->peker = substr(
			$this->peker,
			0,
			strrpos( 
				$this->peker,
				"->oppdrag["
			)
		);

		break;

	}
	
	return true;
}



//	Leser records i tjeneste 21:
//	Avtalegiro
//	Oppdraget har følgende egenskaper:
//	->antallRecords (heltall): 		Antall records inklusive start- og sluttrecords for oppdrag
//	->antallTransaksjoner (heltall): Antall transaksjoner i forsendelsen
//	->tjeneste (heltall):				Tjenestenummeret = 21
//	->oppdragsnr (heltall):				Oppdragets løpenummer i forsendelsen
//	->oppdragstype (heltall):			Oppdragstype:
//										24 = Liste over egne kunders faste betalingsoppdrag
// For Oppdragstype 24:
//	->oppdragskonto (heltall):				Fakturautsteders bankkontonummer
//	->transaksjoner (array av stdClass-objekter):	Transaksjoene i oppdraget
//		->transaksjonsnr (heltall):			Løpenr i oppdraget
//		->tjeneste (heltall):				Tjenestenummeret = 21
//		->transaksjonstype (heltall):		94
//		->registreringstype (heltall):			Registreringstype:
//												0 = Alle faste betalingsoppdrag
//												1 = Nye/endrede faste betalingsoppdrag
//												2 = Slettede faste betalingsoppdrag
//		->kid (streng):						Kundeidentifikasjon for avtalen
//		->skriftligVarsel (streng)			Ønsker betaler skriftlig varsel?
//												J = betaler ønsker skriftlig varsel
//												N = betaler ønsker ikke skriftlig varsel
/****************************************/
//	--------------------------------------
//	retur:	(boolsk) Suksessparameter
public function bbsLesTjeneste21( $record, $recordtype ) {

	// Forskjellig behandling av ulike oppdragstyper i tjeneste 21
	$type = intval(substr( $record, 4, 2 ));
	
	switch( $type ) {
	
	//	Oppdragstype 24:
	//	Oppdrag med liste over egne kunders faste betalingsoppdrag	
	case 24:

		switch( $recordtype ) {

		case 20:	// Startrecord oppdrag

			// Et nytt oppdrag legges til på nåværende nivå,
			// og pekeren flyttes inn i det nye oppdragsobjektet 
			$oppdrag = eval("return $this->peker;");
			settype( $oppdrag->oppdrag, 'array' );
			$this->peker .= "->oppdrag[ " . ($index = count( $oppdrag->oppdrag )) . " ]";
			settype( $oppdrag->oppdrag[ $index ], 'object' );
			$oppdrag = eval("return $this->peker;");
		
			$oppdrag->tjeneste		= 21;
			$oppdrag->oppdragstype	= 24;
			$oppdrag->oppdragsnr	= intval(substr( $record, 17, 7 ));
			$oppdrag->oppdragskonto	= intval(substr( $record, 24, 11 ));

			break;
		
		case 88:	// Sluttrecord oppdrag
		
			$oppdrag = eval("return $this->peker;");

			$oppdrag->antallTransaksjoner
				= (int)substr( $record, 8, 8 );
			$oppdrag->antallRecords
				= (int)substr( $record, 16, 8 );


			if( $oppdrag->antallTransaksjoner != count( $oppdrag->transaksjoner ) ) {
				$this->msg = "Antall transaksjoner (" . count( $oppdrag->transaksjoner ) . ") stemmer ikke over ens med hva som er oppgitt i oppdragets sluttrecord ({$oppdrag->antallTransaksjoner}).";
				return false;
			}

			// Flytt pekeren ett nivå tilbake idet oppdraget avsluttes
			$this->peker = substr(
				$this->peker,
				0,
				strrpos( 
					$this->peker,
					"->oppdrag["
				)
			);

			break;

		}
	
		break;
		
	//	Oppdrags- / transaksjonstype 94:
	//	Record egne kunders faste betalingsoppdrag	
	case 94:

		switch( $recordtype ) {
		
		case 70:	// Record egne kunders faste betalingsoppdrag

			$oppdrag = eval("return $this->peker;");

			$transaksjonsnr	= intval(substr( $record, 8, 7 ));
		
			settype(
				$oppdrag->transaksjoner[$transaksjonsnr - 1],
				'object'
			);
		
			$transaksjon = $oppdrag
				->transaksjoner[$transaksjonsnr - 1];

			$transaksjon->tjeneste
				= 21;
			$transaksjon->transaksjonstype
				= 94;
			$transaksjon->transaksjonsnr
				= $transaksjonsnr;
			$transaksjon->registreringstype
				= (int)substr($record, 15, 1);
				// 0 = Alle faste betalingsoppdrag tilknyttet betalingsmottakers avtale
				// 1 = Nye/endrede faste betalingsoppdrag
				// 2 = Slettede faste betalingsoppdrag
			$transaksjon->kid
				= trim(substr($record, 16, 25));
			$transaksjon->skriftligVarsel
				= substr($record, 41, 1);
		
			break;

		}
	
		break;
	}
		
	return true;
}



//	Leser records i tjeneste 42:
//	eFaktura
//	Oppdraget har følgende egenskaper:
//	->antallRecords (heltall): 		Antall records inklusive start- og sluttrecords for oppdrag
//	->antallTransaksjoner (heltall): Antall transaksjoner i forsendelsen
//	->tjeneste (heltall):				Tjenestenummeret er alltid 42
//	->oppdragstype (heltall):			Oppdragstype:
//										94 = Påmelding efakturaavtale
//	->oppdragsnr (heltall):				Oppdragets løpenummer i forsendelsen
//										(MMDD (måned, dag) + løpenummer)
// For Oppdragstype 94:
//	->oppdragskonto (heltall):				Fakturautsteders bankkontonummer
//	->referanseFakturautsteder (streng):	Fakturautsteders referansenr hos NETS i formatet
//											NOR(organisasjonsnummer)-x, For eksempel NOR123456789-1
//	->transaksjoner (array av stdClass-objekter):	Transaksjoene i oppdraget
//		->avtalestatus (streng):			Status for avtalen:
//											P = pending
//											A = Aktiv
//											D = deleted
//											N = NoActive (Avtalen er ikke godkjent)
//		->avtalevalg (streng):				Instruks for avtalen (EnrollmentType):
//											A = ADD
//											C = CHANGE
//											D = DELETE
//		->brukerId (streng)					Må oppgis på svarfil fra utsteder til Nets
//		->efakturaRef (streng):				Efakturareferanse
//		->fornavn (streng)					Fornavn
//		->etternavn (streng)				Etternavn
//		->feilkode (heltall)				Angir feilkode hvis avtalen ikke settes til aktiv:
//											01 = Oppgitt eFaktura referanse er ugyldig (se papir-faktura)
//											02 = Oppgitte personopplysninger er ikke i samsvar med kunderegister (se papir-faktura)
//											03 = Det tilbys ikke eFaktura for dette produktet
//		->feilmelding (streng)				Forklaring av feilkode
//		->forbruker (stdclass objekt)		Forbrukeradresse:
//			->adresse1 (streng)				Adresselinje 1
//			->adresse2 (streng)				Adresselinje 2
//			->landskode (streng)			Landskode
//			->postnr (streng)				Postnr / postkode
//			->poststed (streng)				Poststed
//			->telefon (streng)				Telefonnr
//			->telefax (streng)				Telefaksnr
//			->email (streng)				Epostadresse
//		->tjeneste (heltall):				Tjenestenummeret er alltid 42
//		->transaksjonsnr (heltall):			Løpenr i oppdraget
//		->transaksjonstype (heltall):		Oppdragstype:
//											94 = Påmelding efakturaavtale
/****************************************/
//	--------------------------------------
//	retur:	(boolsk) Suksessparameter
public function bbsLesTjeneste42( $record, $recordtype ) {

	// Forskjellig behandling av ulike oppdragstyper i tjeneste 42
	$type = intval(substr( $record, 4, 2 ));
	
	switch( $type ) {
	
	//	Oppdragstype 3:
	//	eFakturatransaksjoner
	case 3: {

		switch( $recordtype ) {

		// Recordtype Beløpspost 1
		case 30: {
			$oppdrag = eval("return $this->peker;");
			$transaksjonsnr	= intval(substr( $record, 8, 7 ));
			settype(
				$oppdrag->transaksjoner[$transaksjonsnr - 1],
				'object'
			);
			$transaksjon = $oppdrag
				->transaksjoner[$transaksjonsnr - 1];


			$transaksjon->tjeneste
				= 42;
			$transaksjon->transaksjonstype
				= 3;
			$transaksjon->transaksjonsnr
				= $transaksjonsnr;
			$transaksjon->forfallsdato
				= substr($record, 15, 6);
			$transaksjon->beløp
				= bcdiv(
					substr($record, 32, 17),
					100,
					2
				);
			$transaksjon->kid
				= trim(substr($record, 49, 25));
		
			break;
		}

		// Recortype eFakturareferanser 1
		case 34: {
			$oppdrag = eval("return $this->peker;");
			$transaksjonsnr	= intval(substr( $record, 8, 7 ));
			settype(
				$oppdrag->transaksjoner[$transaksjonsnr - 1],
				'object'
			);
			$transaksjon = $oppdrag
				->transaksjoner[$transaksjonsnr - 1];


			$transaksjon->tjeneste
				= 42;
			$transaksjon->transaksjonstype
				= 3;
			$transaksjon->transaksjonsnr
				= $transaksjonsnr;
			$transaksjon->forfallsdato
				= date_create_from_format(
					'd.m.Y',
					substr($record, 15, 10)
				);
			$transaksjon->beløpsfelt
				= str_replace(array(".", ","), array("", "."), ltrim(substr($record, 25, 20), '0'));
			$transaksjon->fakturatype
				= trim(substr($record, 45, 35));
		
			break;
		}

		// Recortype eFakturareferanser 2
		case 35: {
			$oppdrag = eval("return $this->peker;");
			$transaksjonsnr	= intval(substr( $record, 8, 7 ));
			settype(
				$oppdrag->transaksjoner[$transaksjonsnr - 1],
				'object'
			);
			$transaksjon = $oppdrag
				->transaksjoner[$transaksjonsnr - 1];


			$transaksjon->tjeneste
				= 42;
			$transaksjon->transaksjonstype
				= 3;
			$transaksjon->transaksjonsnr
				= $transaksjonsnr;
			$transaksjon->efakturaRef
				= trim(substr($record, 15, 31));
			$transaksjon->summaryType
				= (bool)substr($record, 46, 1);
				//	0 = Vanlig faktura
				//	1 = Avtalegiro
			$transaksjon->mal
				= (int)substr($record, 47, 2);
				//	1 = Mal 1
				//	2 = Mal 2
			$transaksjon->reklame
				= (bool)substr($record, 49, 1);
			$transaksjon->fakturaUtsteder
				= trim(substr($record, 50, 30));
		
			break;
		}

		// Recordtype Feilkode på fakturanivå (feilkode 040-550)
		case 65: {
			$oppdrag = eval("return $this->peker;");
			$transaksjonsnr	= intval(substr( $record, 8, 7 ));
			settype(
				$oppdrag->transaksjoner[$transaksjonsnr - 1],
				'object'
			);
			$transaksjon = $oppdrag
				->transaksjoner[$transaksjonsnr - 1];


			$transaksjon->tjeneste
				= 42;
			$transaksjon->transaksjonstype
				= 3;
			$transaksjon->transaksjonsnr
				= $transaksjonsnr;
			$transaksjon->feilkode
				= (int)substr($record, 15, 3);
			$transaksjon->feilmelding
				= $this->feilmelding(substr($record, 15, 3));
			$transaksjon->feilreferanse
				= trim(substr($record, 18, 40));
		
			break;
		}

		}
	
		break;
	}

		
	//	Oppdragstype 4:
	//	Kvittering fra NETS for mottatt forsendelse	
	case 4: {

		switch( $recordtype ) {

		// Record Start oppdrag for mottatt forsendelse
		case 63:
			// Et nytt oppdrag legges til på nåværende nivå,
			// og pekeren flyttes inn i det nye oppdragsobjektet 
			$oppdrag = eval("return $this->peker;");
			settype( $oppdrag->oppdrag, 'array' );
			$this->peker .= "->oppdrag[ " . ($index = count( $oppdrag->oppdrag )) . " ]";
			settype( $oppdrag->oppdrag[ $index ], 'object' );
			$oppdrag = eval("return $this->peker;");
		
			$oppdrag->tjeneste			= 42;
			$oppdrag->oppdragstype		= 4;
			$oppdrag->dataavsender		= intval(substr( $record, 8, 8 ));
			$oppdrag->forsendelsesnr	= substr( $record, 16, 7 );
			$oppdrag->datamottaker		= intval(substr( $record, 23, 8 ));
			$oppdrag->referanseFakturautsteder	= trim(substr( $record, 35, 14 ));
			$oppdrag->statusForsendelse	= (int)substr( $record, 49, 1 );
				// 0 = Forsendelsen er mottatt i BBS men ikke ferdig prosessert
				// 1 = Forsendelsen er mottatt i BBS og ferdig prosessert
				// 2 = Forsendelsen er i sin helhet forkastet
			$oppdrag->feilkode			= intval(substr( $record, 50, 3 ));
			$oppdrag->feilmelding		= $this->feilmelding(substr( $record, 50, 3 ));
			$oppdrag->oppdrag			= array(); // Denne er tom, men inkluderes pga oppdragstype 5

			break;

		// Record Slutt oppdrag for mottatt forsendelse
		case 68:
			$oppdrag = eval("return $this->peker;");

			$oppdrag->antallOppdrag
				= (int)substr( $record, 8, 8 );

			// Flytt pekeren ett nivå tilbake idet oppdraget avsluttes
			$this->peker = substr(
				$this->peker,
				0,
				strrpos( 
					$this->peker,
					"->oppdrag["
				)
			);

			break;

		}
	
		break;
	}

		
	//	Oppdragstype 5:
	//	Kvittering fra NETS for prosessert forsendelse	
	case 5: {

		switch( $recordtype ) {

		// Record Start oppdrag for prosessert forsendelse
		case 63: {
			// Et nytt oppdrag legges til på nåværende nivå,
			// og pekeren flyttes inn i det nye oppdragsobjektet 
			$oppdrag = eval("return $this->peker;");
			settype( $oppdrag->oppdrag, 'array' );
			$this->peker .= "->oppdrag[ " . ($index = count( $oppdrag->oppdrag )) . " ]";
			settype( $oppdrag->oppdrag[ $index ], 'object' );
			$oppdrag = eval("return $this->peker;");

			$oppdrag->tjeneste			= 42;
			$oppdrag->oppdragstype		= 5;
			$oppdrag->dataavsender		= intval(substr( $record, 8, 8 ));
			$oppdrag->forsendelsesnr	= substr( $record, 16, 7 );
			$oppdrag->datamottaker		= intval(substr( $record, 23, 8 ));
			$oppdrag->referanseFakturautsteder	= trim(substr( $record, 35, 14 ));
			$oppdrag->statusForsendelse	= (int)substr( $record, 49, 1 );
				// 0 = Forsendelsen er mottatt i BBS men ikke ferdig prosessert
				// 1 = Forsendelsen er mottatt i BBS og ferdig prosessert
				// 2 = Forsendelsen er i sin helhet forkastet
			$oppdrag->feilkode			= intval(substr( $record, 50, 3 ));
			$oppdrag->feilmelding		= $this->feilmelding(substr( $record, 50, 3 ));
			$oppdrag->oppdrag			= array();

			break;
		}

		// Record Slutt oppdrag for prosessert forsendelse
		case 68: {
			$oppdrag = eval("return $this->peker;");

			$oppdrag->antallOppdrag
				= (int)substr( $record, 8, 8 );

			// Flytt pekeren ett nivå tilbake idet oppdraget avsluttes
			$this->peker = substr(
				$this->peker,
				0,
				strrpos( 
					$this->peker,
					"->oppdrag["
				)
			);

			break;
		}

		}
	
		break;
	}

		
	//	Oppdragstype 6:
	//	Kvittering fra NETS for prosesserte transaksjoner	
	case 6: {

		switch( $recordtype ) {

		// Record Start oppdrag for prosessert transaksjoner
		case 64:
			// Et nytt oppdrag legges til på nåværende nivå,
			// og pekeren flyttes inn i det nye oppdragsobjektet 
			$oppdrag = eval("return $this->peker;");
			settype( $oppdrag->oppdrag, 'array' );
			$this->peker .= "->oppdrag[ " . ($index = count( $oppdrag->oppdrag )) . " ]";
			settype( $oppdrag->oppdrag[ $index ], 'object' );
			$oppdrag = eval("return $this->peker;");

			$oppdrag->tjeneste
				= 42;
			$oppdrag->oppdragstype
				= 6;
			$oppdrag->oppdragsnr
				= (int)substr( $record, 17, 7 );
			$oppdrag->oppdragskonto
				= (int)substr($record, 24, 11);
			$oppdrag->statusOppdrag
				= (int)substr($record, 35, 1);
			$oppdrag->feilkode
				= (int)substr($record, 36, 3);
			$oppdrag->feilmelding
				= $this->feilmelding(substr($record, 36, 3));
			$oppdrag->antGodkjenteFakturaer
				= (int)substr($record, 39, 8);
			$oppdrag->antAvvisteFakturaer
				= (int)substr($record, 64, 8);
		
			$oppdrag->transaksjoner
				= array();
		
			break;

		// Record Slutt oppdrag for prosessert transaksjoner
 		case 67:
			$oppdrag = eval("return $this->peker;");
			
			$oppdrag->antGodkjenteFakturaer
				= (int)substr($record, 8, 8);
			$oppdrag->antallTransaksjoner
				= (int)substr($record, 47, 8);

			// Flytt pekeren ett nivå tilbake idet oppdraget avsluttes
			$this->peker = substr(
				$this->peker,
				0,
				strrpos( 
					$this->peker,
					"->oppdrag["
				)
			);

			break;

		}
	
		break;
	}

		
	//	Oppdrags- / transaksjonstype 94:
	//	Påmelding eFaktura / avtalegiro	
	case 94: {

		switch( $recordtype ) {
		case 20:	// Startrecord oppdrag

			// Et nytt oppdrag legges til på nåværende nivå,
			// og pekeren flyttes inn i det nye oppdragsobjektet 
			$oppdrag = eval("return $this->peker;");
			settype( $oppdrag->oppdrag, 'array' );
			$this->peker .= "->oppdrag[ " . ($index = count( $oppdrag->oppdrag )) . " ]";
			settype( $oppdrag->oppdrag[ $index ], 'object' );
			$oppdrag = eval("return $this->peker;");
		
			$oppdrag->tjeneste		= 42;
			$oppdrag->oppdragstype	= 94;
			$oppdrag->oppdragsnr	= intval(substr( $record, 17, 7 ));
			$oppdrag->oppdragskonto	= intval(substr( $record, 24, 11 ));
			$oppdrag->referanseFakturautsteder	= trim(substr( $record, 35, 14 ));

			break;
		
		case 37:	// Avtale info 1 (ny record)

			$oppdrag = eval("return $this->peker;");

			$transaksjonsnr	= intval(substr( $record, 8, 7 ));
		
			settype(
				$oppdrag->transaksjoner[$transaksjonsnr - 1],
				'object'
			);
		
			$transaksjon = $oppdrag
				->transaksjoner[$transaksjonsnr - 1];

			$transaksjon->tjeneste
				= 42;
			$transaksjon->transaksjonstype
				= 94;
			$transaksjon->transaksjonsnr
				= $transaksjonsnr;
			$transaksjon->avtalevalg
				= substr($record, 15, 1);
				//	A = add, C = change eller D = delete
			$transaksjon->efakturaRef
				= trim(substr($record, 17, 31));
		
			break;

		case 38:	// Avtale info 2 (ny record)

			$oppdrag = eval("return $this->peker;");

			$transaksjonsnr	= intval(substr( $record, 8, 7 ));
		
			settype(
				$oppdrag->transaksjoner[$transaksjonsnr - 1],
				'object'
			);
		
			$transaksjon = $oppdrag
				->transaksjoner[$transaksjonsnr - 1];

			$transaksjon->avtalestatus
				= substr($record, 15, 1);
				//	P = pending, A = aktiv eller D = deleted
			$transaksjon->feilkode
				= substr($record, 16, 2);
			$transaksjon->feilmelding
				= $this->feilmelding(substr($record, 18, 40));
			$transaksjon->feilmelding
				= $transaksjon->feilkode == '01'
				? "Oppgitt eFaktura referanse er ugyldig (se papir-faktura)"
				: (
					$transaksjon->feilkode == '02'
					? "	Oppgitte personopplysninger er ikke i samsvar med kunderegister (se papir-faktura)"
					: (
						$transaksjon->feilkode == '03'
						? "Det tilbys ikke eFaktura for dette produktet"
						: ""
					)
				);
		
			break;

		case 39:	// Avtale navn (ny record)
		
			$oppdrag = eval("return $this->peker;");

			$transaksjonsnr	= intval(substr( $record, 8, 7 ));
		
			settype(
				$oppdrag->transaksjoner[$transaksjonsnr - 1],
				'object'
			);
		
			$transaksjon = $oppdrag
				->transaksjoner[$transaksjonsnr - 1];

			$transaksjon->fornavn
				= trim(substr($record, 15, 30));
			$transaksjon->etternavn
				= trim(substr($record, 45, 30));
		
			break;

		case 40:	// Adressepost 1 (navn/postnr/sted)
		
			$oppdrag = eval("return $this->peker;");

			$transaksjonsnr	= intval(substr( $record, 8, 7 ));
		
			settype(
				$oppdrag->transaksjoner[$transaksjonsnr - 1],
				'object'
			);
		
			$transaksjon = $oppdrag
				->transaksjoner[$transaksjonsnr - 1];
			$melding = "";
			switch(  substr($record, 15, 1) ) {
			case 2:
				$melding = "forbruker";
				break;
			}
			settype($transaksjon->$melding, 'object');

			$transaksjon->$melding->postnr
				= trim(substr($record, 46, 7));
			$transaksjon->$melding->poststed
				= trim(substr($record, 53, 25));
		
			break;

		case 41:	// Adressepost 2 (postboks/gate/vei)
		
			$oppdrag = eval("return $this->peker;");

			$transaksjonsnr	= intval(substr( $record, 8, 7 ));
		
			settype(
				$oppdrag->transaksjoner[$transaksjonsnr - 1],
				'object'
			);
		
			$transaksjon = $oppdrag
				->transaksjoner[$transaksjonsnr - 1];
			$melding = "";
			switch(  substr($record, 15, 1) ) {
			case 2:
				$melding = "forbruker";
				break;
			}
			settype($transaksjon->$melding, 'object');

			$transaksjon->$melding->adresse1
				= trim(substr($record, 16, 30));
			$transaksjon->$melding->adresse2
				= trim(substr($record, 46, 30));
			$transaksjon->$melding->landskode
				= trim(substr($record, 76, 3));
		
			break;

		case 22:	// Adressepost 3 (telefon/telefaks forbruker)
		
			$oppdrag = eval("return $this->peker;");

			$transaksjonsnr	= intval(substr( $record, 8, 7 ));
		
			settype(
				$oppdrag->transaksjoner[$transaksjonsnr - 1],
				'object'
			);
		
			$transaksjon = $oppdrag
				->transaksjoner[$transaksjonsnr - 1];
			$melding = "";
			switch(  substr($record, 15, 1) ) {
			case 2:
				$melding = "forbruker";
				break;
			}
			settype($transaksjon->$melding, 'object');

			$transaksjon->$melding->telefon
				= trim(substr($record, 16, 20));
			$transaksjon->$melding->telefax
				= trim(substr($record, 36, 20));
		
			break;

		case 23:	// Adressepost 4 (email forbruker)
		
			$oppdrag = eval("return $this->peker;");

			$transaksjonsnr	= intval(substr( $record, 8, 7 ));
		
			settype(
				$oppdrag->transaksjoner[$transaksjonsnr - 1],
				'object'
			);
		
			$transaksjon = $oppdrag
				->transaksjoner[$transaksjonsnr - 1];
			$melding = "";
			switch(  substr($record, 15, 1) ) {
			case 2:
				$melding = "forbruker";
				break;
			}
			settype($transaksjon->$melding, 'object');

			$transaksjon->$melding->email
				= trim(substr($record, 16, 64));
		
			break;

		case 28:	// Bruker ID (ny record)
		
			$oppdrag = eval("return $this->peker;");

			$transaksjonsnr	= intval(substr( $record, 8, 7 ));
		
			settype(
				$oppdrag->transaksjoner[$transaksjonsnr - 1],
				'object'
			);
		
			$transaksjon = $oppdrag
				->transaksjoner[$transaksjonsnr - 1];

			$transaksjon->brukerId
				= trim( substr( $record, 15, 35 ) );
		
			break;

		case 88:	// Sluttrecord oppdrag
		
			$oppdrag = eval("return $this->peker;");

			$oppdrag->antallTransaksjoner
				= (int)substr( $record, 8, 8 );
			$oppdrag->antallRecords
				= (int)substr( $record, 16, 8 );


			if( $oppdrag->antallTransaksjoner != count( $oppdrag->transaksjoner ) ) {
				$this->msg = "Antall transaksjoner (" . count( $oppdrag->transaksjoner ) . ") stemmer ikke over ens med hva som er oppgitt i oppdragets sluttrecord ({$oppdrag->antallTransaksjoner}).";
				return false;
			}

			// Flytt pekeren ett nivå tilbake idet oppdraget avsluttes
			$this->peker = substr(
				$this->peker,
				0,
				strrpos( 
					$this->peker,
					"->oppdrag["
				)
			);

			break;

		}
	
		break;
	}
	}
		
	return true;
}



// Henter bestemte oppdrag fra forsendelsen
/****************************************/
//	--------------------------------------
//	retur:	(array) Oppdragene som tilfredsstiller kriteriene
public function hentOppdrag( $tjeneste = null, $oppdragstype = null, $avtaleId = null, $kunde = null ) {
	if (!$this->gyldig or !$kunde) {
		return false;
	}
	
	
	$resultat = array();
	
	foreach( $this->oppdrag as $oppdrag ) {
		if( (
				!$tjeneste
				or $tjeneste == $oppdrag->tjeneste
			)
			and (
				!$oppdragstype
				or $oppdragstype == $oppdrag->oppdragstype
			)
			and (
				!$avtaleId
				or $avtaleId == $oppdrag->avtaleId
			)
		) {
			$resultat[0] = $oppdrag;
		}
	}
	return $resultat;
}



// Skriv ut i bbs-format
/****************************************/
//	--------------------------------------
//	retur:	(boolsk) Suksess
public function skriv() {
	
	$this->_nummererOppdrag();
	
	$this->_skrivStartrecord();
	$this->antallRecords = 1;
	
	foreach( $this->oppdrag as $oppdrag ) {
		$this->skrivOppdrag( $oppdrag );
		$this->antallRecords += @$oppdrag->antallRecords;
	}
		
	$this->antallRecords ++;
	$this->_skrivSluttrecord();

	return $this->records;
}



// Skriv ut et oppdrag i bbs-format
/****************************************/
//	--------------------------------------
public function skrivOppdrag( $oppdrag ) {
	if( isset( $oppdrag->records ) and is_array( $oppdrag->records ) and $oppdrag->records) {
	
		$records = $this->rensRecords( $oppdrag->records );
		$start = $records[0];
		$slutt = end( $records );

		array_merge( $this->records, $oppdrag );
		$this->antallRecords += count( $records );	
	}
	else {
		$tjeneste = $oppdrag->tjeneste;
		$this->{"skrivTjeneste{$oppdrag->tjeneste}"}( $oppdrag );
		$this->records = array_merge($this->records, $oppdrag->records);
	}
}



//	skriver records for tjeneste 21:
//	eFaktura
/****************************************/
//	$oppdrag (stdClass)
//		->tjeneste (heltall):					Tjeneste 21 for avtalegiro
//		->oppdragstype (heltall):				Skal være 0
//		->oppdragsnr (heltall):					Oppdragets løpenummer i forsendelsen
//		->oppdragskonto (streng):				Betalingsmottakers bankkonto iflg avtale med NETS
//		->transaksjoner (array):				Alle transaksjonene i oppdraget som stdclass-objekter:
//			->transaksjonsnr (heltall):			automatisk(?)
//			->forfallsdato (DateTime-objekt):	Forfallsdato
//			->mobilnr (streng):					Mobilnummer for varsling med SMS
//			->beløp (tall):						Beløpet oppgitt i kroner med maks to desimaler
//			->kid (streng):						Gyldig KID-nr i hht avtale
//			->kortNavn (streng):				Forkortet navn
//			->fremmedreferanse (streng):		Tekst på betalers kontoutskrift og AvtaleGiro info
//			->spesifikasjon (streng)			Forklarende tekst. Maks 42 linjer á 80 tegn
//	--------------------------------------
//	retur:	(boolsk) Suksessparameter
public function skrivTjeneste21( $oppdrag ) {
	if ( $oppdrag->tjeneste != 21 ) {
		return;
	}

	//	Ulik behandling for ulike oppdragstyper

	// Oppdragstype 0 oversendelse av trekk-krav
	if ( $oppdrag->oppdragstype == 0 ) {
		$oppdrag->records = array();

		$leiebase = new Leiebase;
		
		// Angi antall transaksjoner i oppdraget og i forsendelsen
		$oppdrag->antallTransaksjoner = count( $oppdrag->transaksjoner );
		$this->antallTransaksjoner += $oppdrag->antallTransaksjoner;

		if( !isset( $oppdrag->antallRecords ) ) {
			$oppdrag->antallRecords = 0;
		}
		
		$oppdrag->sumBeløp = 0;
		
		// Skriv startrecord for oppdraget
		$oppdrag->records[] = 
		"NY" /* (formatkode) */
		. "21" /* (tjenestekode) */
		. "00" /* (oppdragstype) */
		. "20"  /* (Startrecord oppdrag) */
		. str_repeat("0", 9)  /* (filler) */
		. $this->_num( $oppdrag->oppdragsnr, 7)
		. $this->_num( $oppdrag->oppdragskonto, 11)
		. str_repeat("0", 45);  /* (filler) */
	
		foreach( $oppdrag->transaksjoner as $index => $transaksjon ) {

			if( $transaksjon instanceof Giro ) {
				$records = $this->rensRecords( $transaksjon->gjengi(
					'fbo-krav',
					array(
						'transaksjonsnummer'	=> $index + 1
					)
				));

				// Beregn oppdragssummen
				$oppdrag->sumBeløp = bcadd(
					$oppdrag->sumBeløp,
					$transaksjon->hent('utestående'),
					2
				);

				// Finn første forfallsdato i oppdraget
				if( !isset( $oppdrag->førsteForfall ) ) {
					$oppdrag->førsteForfall = $transaksjon->hent('forfall');
				}
				else {
					$oppdrag->førsteForfall = min(
						$oppdrag->førsteForfall,
						$transaksjon->hent('forfall')
					);
				}

				// Finn siste forfallsdato i oppdraget
				if( !isset( $oppdrag->sisteForfall ) ) {
					$oppdrag->sisteForfall = $transaksjon->hent('forfall');
				}
				else {
					$oppdrag->sisteForfall = max(
						$oppdrag->sisteForfall,
						$transaksjon->hent('forfall')
					);
				}

				// Finn første forfallsdato i forsendelsen
				if( $this->dato === null ) {
					$this->dato = $oppdrag->førsteForfall;
				}
				else {
					$this->dato = min(
						$oppdrag->førsteForfall,
						$this->dato
					);
				}

				$oppdrag->records = array_merge( $oppdrag->records, $records );
				$oppdrag->antallRecords += count( $records );
			}

			else if( $transaksjon instanceof stdclass and @$transaksjon->records ) {
				$records = $this->rensRecords( $transaksjon->records );
				$oppdrag->records = array_merge( $oppdrag->records, $records );
				$oppdrag->antallRecords += count( $records );
			}

			else {		
				// Det må skrives transaksjonsrecords		
			
				$transaksjon->transaksjonsnr = $index + 1;			

				// Beregn oppdragssummen
				$oppdrag->sumBeløp = bcadd(
					$oppdrag->sumBeløp,
					$transaksjon->beløp,
					2
				);

				// Finn første forfallsdato i oppdraget
				if( !isset( $oppdrag->førsteForfall ) ) {
					$oppdrag->førsteForfall = $transaksjon->forfallsdato;
				}
				else {
					$oppdrag->førsteForfall = min(
						$oppdrag->førsteForfall,
						$transaksjon->forfallsdato
					);
				}

				// Finn siste forfallsdato i oppdraget
				if( !isset( $oppdrag->sisteForfall ) ) {
					$oppdrag->sisteForfall = $transaksjon->forfallsdato;
				}
				else {
					$oppdrag->sisteForfall = max(
						$oppdrag->sisteForfall,
						$transaksjon->forfallsdato
					);
				}

				// Finn første forfallsdato i forsendelsen
				if( $this->dato === null ) {
					$this->dato = $oppdrag->førsteForfall;
				}
				else {
					$this->dato = min(
						$oppdrag->førsteForfall,
						$this->dato
					);
				}

				$transaksjon->records[] = 
					"NY" /* (formatkode) */
					. "21" /* (tjenestekode) */
					. $this->_num( $transaksjon->transaksjonstype, 2)
					. "30"  /* (Beløpspost 1) */
					. $this->_num( $transaksjon->transaksjonsnr, 7)
					. $transaksjon->forfallsdato->format('dmy')
					. $this->_num( $transaksjon->mobilnr, 11, " ")
					. $this->_num( bcmul($oppdrag->beløp, 100, 0), 17)
					. $this->_num( $oppdrag->kid, 25, " ")
					. str_repeat("0", 6);  /* (filler) */
		
				$transaksjon->records[] = 
					"NY" /* (formatkode) */
					. "21" /* (tjenestekode) */
					. $this->_num( $transaksjon->transaksjonstype, 2)
					. "31"  /* (Beløpspost 1) */
					. $this->_num( $transaksjon->transaksjonsnr, 7)
					. $this->_str( $transaksjon->kortNavn, 10)
					. str_repeat(" ", 25)  /* (filler) */
					. $this->_str( $transaksjon->fremmedreferanse, 25)
					. str_repeat("0", 5);  /* (filler) */
					
				if( $transaksjon->spesifikasjon ) {
				
					$transaksjon->spesifikasjon = array_slice(explode(
						"\n",
						wordwrap( $transaksjon->spesifikasjon, 80, "\n", true )
					), 0, 42);
				
					foreach( $transaksjon->spesifikasjon as $linjenr => $tekstlinje) {
						
						if( trim( $tekstlinje ) ) {
		
							$transaksjon->records[] = 
								"NY" /* (formatkode) */
								. "21" /* (tjenestekode) */
								. $this->_num( $transaksjon->transaksjonstype, 2)
								. "49"  /* (Spesifikasjonsrecord) */
								. $this->_num( $transaksjon->transaksjonsnr, 7)
								. "4" /* (betalingsvarsel) */
								. $this->_num( $linjenr + 1, 3)
								. "1" /* (kolonne) */
								. $this->_str( substr( $tekstlinje, 0, 40 ), 40)
								. str_repeat("0", 20);  /* (filler) */
					
						}
						
						if( strlen( trim( $tekstlinje ) ) > 40 ) {
		
							$transaksjon->records[] = 
								"NY" /* (formatkode) */
								. "21" /* (tjenestekode) */
								. $this->_num( $transaksjon->transaksjonstype, 2)
								. "49"  /* (Spesifikasjonsrecord) */
								. $this->_num( $transaksjon->transaksjonsnr, 7)
								. "4" /* (betalingsvarsel) */
								. $this->_num( $linjenr + 1, 3)
								. "2" /* (kolonne) */
								. $this->_str( substr( $tekstlinje, 40, 40 ), 40)
								. str_repeat("0", 20);  /* (filler) */
					
						}
					}
				
				}
		
				$records = $this->rensRecords( $transaksjon->records );
			}
		}

		$oppdrag->antallRecords = count( $oppdrag->records ) + 1;
		
		// Skriv sluttrecord for oppdraget
		$oppdrag->records[] = 
		"NY" /* (formatkode) */
		. "21" /* (tjenestekode) */
		. "00" /* (transaksjonstype) */
		. "88"  /* (Sluttrecord oppdrag) */
		. $this->_num( $oppdrag->antallTransaksjoner, 8)
		. $this->_num( $oppdrag->antallRecords, 8)
		. $this->_num( bcmul( $oppdrag->sumBeløp, 100, 0 ), 17)
		. $oppdrag->førsteForfall->format('dmy')
		. $oppdrag->sisteForfall->format('dmy')
		. str_repeat("0", 27);  /* (filler) */
	
		// Beregn forsendelsessummen
		$this->sumBeløp = bcadd(
			$this->sumBeløp,
			$oppdrag->sumBeløp,
			2
		);

	}

	// Oppdragstype 36 sletteanmodninger trekkrav
	if ( $oppdrag->oppdragstype == 36) {
		$oppdrag->records = array();

		$leiebase = new Leiebase;
		
		// Angi antall transaksjoner i oppdraget og i forsendelsen
		$oppdrag->antallTransaksjoner = count( $oppdrag->transaksjoner );
		$this->antallTransaksjoner += $oppdrag->antallTransaksjoner;

		if( !isset( $oppdrag->antallRecords ) ) {
			$oppdrag->antallRecords = 0;
		}
		
		$oppdrag->førsteForfall = null;
		$oppdrag->sisteForfall = null;
		$oppdrag->sumBeløp = 0;
		
		// Skriv startrecord for oppdraget
		$oppdrag->records[] = 
		"NY" /* (formatkode) */
		. "21" /* (tjenestekode) */
		. "36" /* (oppdragstype) */
		. "20"  /* (Startrecord oppdrag) */
		. str_repeat("0", 9)  /* (filler) */
		. $this->_num( $oppdrag->oppdragsnr, 7)
		. $this->_num( $oppdrag->oppdragskonto, 11)
		. str_repeat("0", 45);  /* (filler) */
	
		foreach( $oppdrag->transaksjoner as $index => $transaksjon ) {

			if( $transaksjon instanceof Giro ) {
				$records = $this->rensRecords( $transaksjon->gjengi(
					'fbo-krav',
					array(
						'transaksjonsnummer'	=> $index + 1,
						'transaksjonstype'		=> 93 // Sletteanmodning
					)
				));
				$oppdrag->records = array_merge( $oppdrag->records, $records );
				$oppdrag->antallRecords += count( $records );
			}
			else if( $transaksjon instanceof stdclass and @$transaksjon->records ) {
				$records = $this->rensRecords( $transaksjon->records );
				$oppdrag->records = array_merge( $oppdrag->records, $records );
				$oppdrag->antallRecords += count( $records );
			}
			else {		
				// Det må skrives transaksjonsrecords		
			
				$transaksjon->transaksjonsnr = $index + 1;			
				$oppdrag->sisteForfall = max($transaksjon->forfallsdato, $oppdrag->sisteForfall );
				$oppdrag->sumBeløp = bcadd( $oppdrag->sumBeløp, $transaksjon->beløp, 2);

				if( $oppdrag->førsteForfall === null ) {
					$oppdrag->førsteForfall = $transaksjon->forfallsdato;
				}
				else {
					$oppdrag->førsteForfall = min($transaksjon->forfallsdato, $oppdrag->førsteForfall );
				}

				$transaksjon->records[] = 
					"NY" /* (formatkode) */
					. "21" /* (tjenestekode) */
					. "93" /* (transaksjonstype) */
					. "30"  /* (Beløpspost 1) */
					. $this->_num( $transaksjon->transaksjonsnr, 7)
					. $transaksjon->forfallsdato->format('dmy')
					. $this->_num( $transaksjon->mobilnr, 11, " ")
					. $this->_num( bcmul( $transaksjon->beløp, 100, 0 ), 17)
					. $this->_num( $transaksjon->kid, 25, " ")
					. str_repeat("0", 6);  /* (filler) */
		
				$records = $this->rensRecords( $transaksjon->records );
				$oppdrag->records = array_merge( $oppdrag->records, $records );
			}
		}
		
		$oppdrag->antallRecords = count( $oppdrag->records ) + 1;
		
		// Skriv sluttrecord for oppdraget
		$oppdrag->records[] = 
		"NY" /* (formatkode) */
		. "21" /* (tjenestekode) */
		. "36" /* (oppdragstype) */
		. "88"  /* (Sluttrecord oppdrag) */
		. $this->_num( $oppdrag->antallTransaksjoner, 8)
		. $this->_num( $oppdrag->antallRecords, 8)
		. $this->_num( bcmul( $oppdrag->sumBeløp, 100, 0 ), 17)
		. $oppdrag->førsteForfall->format('dmy')
		. $oppdrag->sisteForfall->format('dmy')
		. str_repeat("0", 27);  /* (filler) */
	
		// Beregn forsendelsessummen
		$this->sumBeløp = bcadd(
			$this->sumBeløp,
			$oppdrag->sumBeløp,
			2
		);

	}

	return true;
}



//	skriver records for tjeneste 42:
//	eFaktura
/****************************************/
//	$oppdrag (stdClass)
//	--------------------------------------
//	retur:	(boolsk) Suksessparameter
public function skrivTjeneste42( $oppdrag ) {
	if ( $oppdrag->tjeneste != 42 ) {
		return;
	}

	//	Ulik behandling for ulike oppdragstyper

	// Oppdragstype 3 oversendelse av eFakturaer
	if ( $oppdrag->oppdragstype == 3 ) {
		$oppdrag->records = array();

		$leiebase = new Leiebase;
		
		// Angi antall transaksjoner i oppdraget og i forsendelsen
		$oppdrag->antallTransaksjoner = count( $oppdrag->transaksjoner );
		$this->antallTransaksjoner += $oppdrag->antallTransaksjoner;

		if( !isset( $oppdrag->antallRecords ) ) {
			$oppdrag->antallRecords = 0;
		}
		
		$oppdrag->sumBeløp = 0;
		
		// Skriv startrecord for oppdraget
		$oppdrag->records[] = 
		"NY" /* (formatkode) */
		. "42" /* (tjenestekode) */
		. "03" /* (transaksjonstype) */
		. "20"  /* (Startrecord oppdrag) */
		. str_repeat("0", 9)  /* (filler) */
		. $this->_num( $oppdrag->oppdragsnr, 7)
		. $this->_num( $oppdrag->oppdragskonto, 11)
		. $this->_str( $oppdrag->referanseFakturautsteder, 14)
		. str_repeat("0", 31);  /* (filler) */
	
		foreach( $oppdrag->transaksjoner as $index => $transaksjon ) {

			if( $transaksjon instanceof Giro ) {
				$summaryType = (bool)$transaksjon->hent('fboTrekkrav');
				$records = $this->rensRecords( $transaksjon->gjengi(
					'efaktura',
					array(
						'transaksjonsnummer'	=> $index + 1,
						'summaryType'			=> ($summaryType ? "1" : "0")
					)
				));

				// Beregn oppdragssummen
				$oppdrag->sumBeløp = bcadd(
					$oppdrag->sumBeløp,
					$transaksjon->hent('utestående'),
					2
				);

				// Finn første forfallsdato i oppdraget
				if( !isset( $oppdrag->førsteForfall ) ) {
					$oppdrag->førsteForfall = $transaksjon->hent('forfall');
				}
				else {
					$oppdrag->førsteForfall = min(
						$oppdrag->førsteForfall,
						$transaksjon->hent('forfall')
					);
				}

				// Finn siste forfallsdato i oppdraget
				if( !isset( $oppdrag->sisteForfall ) ) {
					$oppdrag->sisteForfall = $transaksjon->hent('forfall');
				}
				else {
					$oppdrag->sisteForfall = max(
						$oppdrag->sisteForfall,
						$transaksjon->hent('forfall')
					);
				}

				// Finn første forfallsdato i forsendelsen
				if( $this->dato === null ) {
					$this->dato = $oppdrag->førsteForfall;
				}
				else {
					$this->dato = min(
						$oppdrag->førsteForfall,
						$this->dato
					);
				}

				$oppdrag->records = array_merge( $oppdrag->records, $records );
				$oppdrag->antallRecords += count( $records );
			}
			else if( $transaksjon instanceof stdclass and @$transaksjon->records ) {
				$records = $this->rensRecords( $transaksjon->records );

				// Beregn oppdragssummen
				$oppdrag->sumBeløp = bcadd(
					$oppdrag->sumBeløp,
					$transaksjon->beløp,
					2
				);

				// Finn første forfallsdato i oppdraget
				if( !isset( $oppdrag->førsteForfall ) ) {
					$oppdrag->førsteForfall = $transaksjon->forfallsdato;
				}
				else {
					$oppdrag->førsteForfall = min(
						$oppdrag->førsteForfall,
						$transaksjon->forfallsdato
					);
				}

				// Finn siste forfallsdato i oppdraget
				if( !isset( $oppdrag->sisteForfall ) ) {
					$oppdrag->sisteForfall = $transaksjon->forfallsdato;
				}
				else {
					$oppdrag->sisteForfall = max(
						$oppdrag->sisteForfall,
						$transaksjon->forfallsdato
					);
				}

				// Finn første forfallsdato i forsendelsen
				if( $this->dato === null ) {
					$this->dato = $oppdrag->førsteForfall;
				}
				else {
					$this->dato = min(
						$oppdrag->førsteForfall,
						$this->dato
					);
				}
			}
		}

		$oppdrag->antallRecords = count( $oppdrag->records ) + 1;
		
		// Skriv sluttrecord for oppdraget
		$oppdrag->records[] = 
		"NY" /* (formatkode) */
		. "42" /* (tjenestekode) */
		. "03" /* (transaksjonstype) */
		. "88"  /* (Sluttrecord oppdrag) */
		. $this->_num( $oppdrag->antallTransaksjoner, 8)
		. $this->_num( $oppdrag->antallRecords, 8)
		. $this->_num( bcmul( $oppdrag->sumBeløp, 100, 0 ), 17)
		. $this->_num( $oppdrag->førsteForfall->format('dmy'), 6)
		. $this->_num( $oppdrag->sisteForfall->format('dmy'), 6)
		. $this->_str( $oppdrag->referanseFakturautsteder, 14)
		. str_repeat("0", 13);  /* (filler) */
	
		// Beregn forsendelsessummen
		$this->sumBeløp = bcadd(
			$this->sumBeløp,
			$oppdrag->sumBeløp,
			2
		);

	}
		

	// Oppdragstype 94 påmelding efaktura
	if ( $oppdrag->oppdragstype == 94 ) {
		$oppdrag->records = array();
		
		// Angi antall transaksjoner i oppdraget og i forsendelsen
		$oppdrag->antallTransaksjoner = count( $oppdrag->transaksjoner );
		$this->antallTransaksjoner += $oppdrag->antallTransaksjoner;
		
		// Skriv startrecord for oppdraget
		$oppdrag->records[] = 
		"NY" /* (formatkode) */
		. "42" /* (tjenestekode) */
		. "94" /* (transaksjonstype) */
		. "20"  /* (Startrecord oppdrag) */
		. str_repeat("0", 9)  /* (filler) */
		. $this->_num( $oppdrag->oppdragsnr, 7)
		. $this->_num( $oppdrag->oppdragskonto, 11)
		. $this->_str( $oppdrag->referanseFakturautsteder, 14)
		. str_repeat("0", 31);  /* (filler) */
	
		foreach( $oppdrag->transaksjoner as $transaksjon ) {

			$oppdrag->records[] = 
			"NY" /* (formatkode) */
			. "42" /* (tjenestekode) */
			. "94" /* (transaksjonstype) */
			. "37"  /* (Avtale info 1 (ny record)) */
			. $this->_num( $transaksjon->transaksjonsnr, 7)
			. $this->_str( $transaksjon->avtalevalg, 1)
			. " "
			. $this->_str( $transaksjon->efakturaRef, 31)
			. str_repeat("0", 32);  /* (filler) */

			$oppdrag->records[] = 
			"NY" /* (formatkode) */
			. "42" /* (tjenestekode) */
			. "94" /* (transaksjonstype) */
			. "38"  /* (Avtale info 2 (ny record)) */
			. $this->_num( $transaksjon->transaksjonsnr, 7)
			. $this->_str( $transaksjon->avtalestatus, 1)
			. $this->_str( @$transaksjon->feilkode, 2)
			. str_repeat(" ", 40)  /* (filler) */
			. str_repeat("0", 22);  /* (filler) */

			$oppdrag->records[] = 
			"NY" /* (formatkode) */
			. "42" /* (tjenestekode) */
			. "94" /* (transaksjonstype) */
			. "39"  /* (Avtale navn (ny record)) */
			. $this->_num( $transaksjon->transaksjonsnr, 7)
			. $this->_str( @$transaksjon->fornavn, 30)
			. $this->_str( @$transaksjon->etternavn, 30)
			. str_repeat("0", 5);  /* (filler) */

			$oppdrag->records[] = 
			"NY" /* (formatkode) */
			. "42" /* (tjenestekode) */
			. "94" /* (transaksjonstype) */
			. "40"  /* (Adressepost 1 (navn/postnr/sted)) */
			. $this->_num( $transaksjon->transaksjonsnr, 7)
			. "2"  /* (Melding 2 = Forbruker) */
			. str_repeat(" ", 30)  /* (filler) */
			. $this->_str( @$transaksjon->forbruker->postnr, 7)
			. $this->_str( @$transaksjon->forbruker->poststed, 25)
			. str_repeat("0", 2);  /* (filler) */

			$oppdrag->records[] = 
			"NY" /* (formatkode) */
			. "42" /* (tjenestekode) */
			. "94" /* (transaksjonstype) */
			. "41"  /* (Adressepost 2 (postboks/gate/vei)) */
			. $this->_num( $transaksjon->transaksjonsnr, 7)
			. "2"  /* (Melding 2 = Forbruker) */
			. $this->_str( @$transaksjon->forbruker->adresse1, 30)
			. $this->_str( @$transaksjon->forbruker->adresse2, 30)
			. $this->_str( @$transaksjon->forbruker->landskode, 3)
			. str_repeat("0", 1);  /* (filler) */

			$oppdrag->records[] = 
			"NY" /* (formatkode) */
			. "42" /* (tjenestekode) */
			. "94" /* (transaksjonstype) */
			. "22"  /* (Adressepost 3 (telefon/telefaks forbruker)) */
			. $this->_num( $transaksjon->transaksjonsnr, 7)
			. "2"  /* (Melding 2 = Forbruker) */
			. $this->_str( @$transaksjon->forbruker->telefon, 20)
			. $this->_str( @$transaksjon->forbruker->telefax, 20)
			. str_repeat("0", 24);  /* (filler) */

			$oppdrag->records[] = 
			"NY" /* (formatkode) */
			. "42" /* (tjenestekode) */
			. "94" /* (transaksjonstype) */
			. "23"  /* (Adressepost 4 (email forbruker)) */
			. $this->_num( $transaksjon->transaksjonsnr, 7)
			. "2"  /* (Melding 2 = Forbruker) */
			. $this->_str( @$transaksjon->forbruker->email, 64);

			$oppdrag->records[] = 
			"NY" /* (formatkode) */
			. "42" /* (tjenestekode) */
			. "94" /* (transaksjonstype) */
			. "28"  /* (Bruker ID (ny record)) */
			. $this->_num( $transaksjon->transaksjonsnr, 7)
			. $this->_str( $transaksjon->brukerId, 35)
			. str_repeat("0", 30);  /* (filler) */
		}

		$oppdrag->antallRecords = count( $oppdrag->records ) + 1;
		
		// Skriv sluttrecord for oppdraget
		$oppdrag->records[] = 
		"NY" /* (formatkode) */
		. "42" /* (tjenestekode) */
		. "94" /* (transaksjonstype) */
		. "88"  /* (Sluttrecord oppdrag) */
		. $this->_num( $oppdrag->antallTransaksjoner, 8)
		. $this->_num( $oppdrag->antallRecords, 8)
		. str_repeat("0", 56);  /* (filler) */
	
	}

	return true;
}



// Sjekk om innholdet faktisk er et gyldig oppdrag
/****************************************/
//	--------------------------------------
//	retur:	(boolsk) Gyldighet
public function valider() {
	return $this->gyldig;
}



// Rydder opp innholdet i et sett med records
/****************************************/
//	--------------------------------------
//	retur:	(array) Gyldige records
public function rensRecords( $records ) {
	if ( !is_array( $records ) ) {
		$records = explode("\n", trim( $records ) );
	}
	foreach( $records as $index => &$record ) {
		if ( substr($record, 0, 2) != "NY" ) {
			unset($records[ $index ]);
		}
	}
	return array_values( $records );
}



}?>