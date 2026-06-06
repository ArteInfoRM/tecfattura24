# Tec Fattura24 Connector

Tec Fattura24 Connector è un modulo PrestaShop di Tecnoacquisti.com® che invia gli ordini a Fattura24 quando una delle regole documento configurate corrisponde allo stato ordine raggiunto.

Il modulo è sviluppato per PrestaShop 1.7.8.11, 8.x, 9.0 e 9.1. Non invia documenti alla creazione dell'ordine e non dipende dal flag PrestaShop di stato pagato: il merchant sceglie quali tipi documento abilitare e quale stato ordine attiva ciascuno.

## Funzionalità principali

- Creazione documento Fattura24 basata sullo stato ordine tramite hook `actionOrderStatusUpdate`.
- Regole documento separate per associare ogni tipo documento Fattura24 al proprio stato ordine PrestaShop.
- Chiave API Fattura24 configurabile con valore mascherato nel back office.
- Test della chiave API dalla pagina di configurazione del modulo.
- Tipi documento Fattura24 supportati: ordine cliente (`C`), fattura elettronica (`FE`), fattura (`I`), fattura forzata (`I-force`) e ricevuta (`R`).
- Numerator ID e Template ID Fattura24 opzionali.
- Invio email Fattura24 opzionale.
- Flag pagato opzionale nella riga pagamento del documento generato.
- Gestione opzionale degli ordini a totale zero.
- Integrazione HTTPS POST con Fattura24 `TestKey` e `SaveDocument`.
- `idRequest` Fattura24 idempotente basato su tipo documento, ID ordine e ID shop.
- Tabella log dedicata con stato documento, tentativi, ID documento Fattura24, risposta API e messaggio di errore.
- Invio manuale o retry dalla pagina ordine del back office PrestaShop.
- Lock MySQL nominale per evitare invii concorrenti dello stesso ordine e tipo documento.
- Dati fattura letti dall'indirizzo di fatturazione PrestaShop.
- Integrazione ArteInvoice per i campi SDI e PEC quando `address.sdi` e `address.pec` sono disponibili.
- Logging debug opzionale tramite `PrestaShopLogger`.

## Requisiti

- PrestaShop 1.7.8.11 o successivo.
- Estensione PHP cURL abilitata.
- Una chiave API Fattura24 valida.
- Almeno una regola documento abilitata con uno stato ordine PrestaShop configurato.
- Dati dell'indirizzo di fatturazione adatti al tipo documento selezionato in Fattura24.

## Installazione

Installa il modulo dal back office PrestaShop oppure montalo nei container locali di sviluppo con lo script moduli del workspace.

Dopo l'installazione, il modulo:

- crea la tabella database `tecfattura24_document`;
- registra `actionOrderStatusUpdate`;
- registra `displayAdminOrder` e `displayAdminOrderSideBottom` per il pannello nella pagina ordine;
- crea i valori di configurazione globali predefiniti.

## Configurazione

Apri la pagina di configurazione del modulo e imposta prima le configurazioni API comuni:

| Campo | Scopo |
|---|---|
| API key | Chiave API Fattura24. Il valore salvato viene mostrato mascherato; lascialo invariato per mantenere la chiave esistente. |
| HTTP timeout | Timeout della richiesta API in secondi. I valori fuori dall'intervallo 5-120 secondi vengono normalizzati a 60. |
| Debug log | Scrive log di debug in `PrestaShopLogger` quando abilitato. |

Poi configura le regole documento. Ogni tipo documento supportato ha una propria riga:

| Campo | Scopo |
|---|---|
| Enabled | Abilita gli invii automatici e manuali per quel tipo documento. |
| Trigger order status | Lo stato PrestaShop che attiva la creazione di quel tipo documento. |
| Document type | Tipo documento Fattura24: `C`, `FE`, `I`, `I-force` oppure `R`. |
| Numerator ID | ID numeratore Fattura24 opzionale. Lascia vuoto per usare il valore predefinito di Fattura24. |
| Template ID | ID template Fattura24 opzionale. Lascia vuoto per usare il valore predefinito di Fattura24. |
| Shop code | Codice breve opzionale usato dalla numerazione personalizzata. Quando è vuoto, il modulo usa `SHOP` più l'ID shop PrestaShop. |
| Custom number | Invia un valore Fattura24 `<Number>` personalizzato. Abilitato di default per gli ordini cliente (`C`) e disabilitato di default per i documenti fiscali. |
| Number format | Formato usato per costruire il numero documento personalizzato. Default per gli ordini cliente: `{order_id}-{shop_code}-{year}`. |
| Send email from Fattura24 | Invia l'email del documento da Fattura24 quando abilitato. |
| Mark document as paid | Scrive `Paid=true` nella riga pagamento Fattura24 quando abilitato. |
| Allow zero-total orders | Consente l'invio di ordini a totale zero. Disabilitato di default. |

Usa il pulsante `Test API key` prima di abilitare le regole documento di produzione.

I campi liberi delle regole documento vengono validati prima del salvataggio:

- `Numerator ID` e `Template ID`: solo cifre, massimo 16 caratteri.
- `Shop code`: solo lettere, numeri, underscore e trattino, massimo 8 caratteri.
- `Number format`: solo lettere, numeri, `_`, `-`, `/`, `.`, parentesi graffe e token supportati.
- `Number` generato: massimo 20 caratteri prima della chiamata API.

## FAQ

### Cosa significa Document type?

`Document type` è il tipo documento Fattura24 inviato nel nodo XML per una specifica regola:

```xml
<DocumentType>...</DocumentType>
```

Valori disponibili:

| Valore | Significato | Uso tipico |
|---|---|---|
| `FE` | Fattura elettronica | Fatturazione elettronica italiana con SDI/PEC. |
| `I` | Fattura | Fattura standard Fattura24. |
| `I-force` | Fattura forzata | Flusso di fattura forzata Fattura24, solo quando quel comportamento è necessario. |
| `R` | Ricevuta | Flusso ricevuta/corrispettivo. |
| `C` | Ordine cliente | Flusso ordine cliente Fattura24. Usalo quando il risultato atteso è una voce in Ordini Cliente Fattura24 invece di un documento fiscale. |

Per `C`, il modulo invia un payload da ordine cliente: include numero ordine e
totali, ma non invia nodi fiscali di pagamento come `FePaymentCode`, `Payments`
o `IdNumerator`.

Gli ordini cliente usano `Number` Fattura24 invece di `IdNumerator`. Di default
il numero viene costruito come `{order_id}-{shop_code}-{year}`, per esempio
`24-SHOP1-2026`. Questo rende gli ordini identificabili tra multinegozio o più
e-commerce senza appoggiarsi alla numerazione fiscale dei documenti.

Il tipo documento configurato fa parte anche della regola di unicità del log del modulo: viene tracciata una riga per ogni ordine, shop e tipo documento. Cambiare tipo documento dopo aver inviato un ordine può creare una riga tracciata separata per lo stesso ordine.

### A cosa servono Numerator ID e Template ID?

Entrambi i campi sono opzionali. Quando sono vuoti, il modulo non li invia e Fattura24 usa le impostazioni predefinite configurate nell'account.

`Numerator ID` viene inviato come:

```xml
<IdNumerator>...</IdNumerator>
```

Usalo solo quando deve essere usato uno specifico numeratore o sezionale Fattura24, per esempio una sequenza dedicata all'e-commerce.

Per gli ordini cliente (`C`), il modulo non invia `IdNumerator`. Usa `Shop
code`, `Custom number` e `Number format` per distinguere la numerazione degli
ordini per anno, shop o sorgente e-commerce.

`Template ID` viene inviato come:

```xml
<IdTemplate>...</IdTemplate>
```

Usalo solo quando deve essere usato uno specifico layout/template documento Fattura24.

Per un primo test, una configurazione prudente è:

- `Document type`: `C` per testare gli ordini cliente, oppure `R` per testare la creazione ricevuta
- `Numerator ID`: vuoto
- `Template ID`: vuoto
- `Send email from Fattura24`: disabilitato
- `Mark document as paid`: disabilitato, salvo che l'ordine di test debba risultare pagato
- `Allow zero-total orders`: disabilitato

### Cosa fa Send email from Fattura24?

Quando è abilitato, il modulo invia `SendEmail=true` nell'XML del documento. Questo chiede a Fattura24 di inviare via email il documento generato al cliente.

Tienilo disabilitato durante i test se non devono partire email reali ai clienti.

### Cosa fa Mark document as paid?

Quando è abilitato, il modulo scrive `Paid=true` nella riga pagamento Fattura24 generata. Questo marca il documento come pagato in Fattura24.

Abilitalo quando lo stato PrestaShop scelto come trigger significa che il pagamento dell'ordine è confermato. Lascialo disabilitato quando stai testando la sola creazione del documento senza modificare lo stato di pagamento in Fattura24.

### Cosa fa Allow zero-total orders?

Quando è disabilitato, gli ordini a totale zero non vengono inviati a Fattura24. Il modulo registra invece un errore nel pannello ordine.

Abilitalo solo quando gli ordini a totale zero, come ordini completamente scontati o omaggi, devono comunque creare un documento Fattura24.

### Che cos'è HTTP timeout?

`HTTP timeout` è il numero massimo di secondi che il modulo attende per le risposte Fattura24 alle richieste `TestKey` e `SaveDocument`.

Il valore predefinito è `60`. I valori sotto `5` o sopra `120` vengono normalizzati a `60`. Mantieni `60` per test normali e produzione, salvo timeout API reali che richiedano un valore diverso.

### Che cos'è Debug log?

Quando è abilitato, il modulo scrive messaggi diagnostici di alto livello in `PrestaShopLogger`, per esempio quando inizia l'invio di un ordine con uno specifico `idRequest` Fattura24.

Tienilo disabilitato nell'uso normale. Abilitalo temporaneamente durante i test o la diagnosi di problemi di invio. Non aggiungere log che espongano chiavi API, XML completo o altri dati sensibili.

## Flusso ordine

1. L'ordine cambia stato in PrestaShop.
2. Il modulo cerca tutte le regole documento abilitate il cui stato trigger corrisponde al nuovo stato ordine.
3. Per ogni regola corrispondente, gli ordini a totale zero vengono saltati salvo abilitazione esplicita in quella regola.
4. Il modulo acquisisce un lock MySQL nominale per ordine, shop e tipo documento.
5. Il modulo crea o reimposta una riga pending in `tecfattura24_document`.
6. Il documento XML Fattura24 viene generato da indirizzo di fatturazione, cliente, righe ordine, sconti, spedizione e dati pagamento.
7. Il modulo chiama Fattura24 `SaveDocument`.
8. Quando Fattura24 restituisce un ID documento, la riga viene marcata come `sent`.
9. In caso di errore, la riga viene marcata come `error` e può essere ritentata manualmente dalla pagina ordine.

Se un documento è già stato inviato per lo stesso ordine, shop e tipo documento, l'elaborazione automatica non lo invia di nuovo. Se un precedente invio automatico è fallito, il modulo richiede un retry manuale dalla pagina ordine.

Quando Fattura24 segnala che il numero documento esiste già, il modulo tratta
l'esito come documento già creato e marca la riga locale come `sent`.

## Numerazione personalizzata

La numerazione personalizzata invia il nodo Fattura24 `Number`. È abilitata di
default per gli ordini cliente (`C`) perché sono documenti non fiscali e spesso
richiedono un riferimento e-commerce chiaro.

Token supportati nel formato:

| Token | Valore |
|---|---|
| `{year}` | Anno dalla data ordine PrestaShop. |
| `{shop_id}` | ID shop PrestaShop. |
| `{shop_code}` | Codice shop della regola, oppure `SHOP` più ID shop quando vuoto. |
| `{order_id}` | ID ordine PrestaShop. |
| `{order_reference}` | Riferimento ordine PrestaShop. |
| `{document_type}` | Tipo documento Fattura24. |

Fattura24 non documenta una lunghezza massima per il nodo `Number` nella pagina
pubblica `SaveDocument`. Il modulo applica un limite conservativo di 20
caratteri prima di chiamare l'API. Se il numero generato è più lungo, l'invio
viene bloccato localmente e il pannello ordine mostra l'errore.

Per i documenti fiscali (`FE`, `I`, `I-force`, `R`), mantieni `Custom number`
disabilitato salvo una ragione precisa per sovrascrivere la numerazione
Fattura24. Per le serie fiscali è preferibile usare `IdNumerator`.

## Mappatura dati Fattura24

L'XML generato include:

- valuta;
- nome cliente, indirizzo, CAP, città, provincia e paese;
- codice fiscale da `Address::dni` quando disponibile;
- partita IVA da `Address::vat_number`, normalizzata rimuovendo il prefisso paese quando presente;
- email cliente;
- nome e descrizione del metodo di pagamento;
- codice pagamento fattura elettronica dedotto dall'etichetta pagamento PrestaShop;
- importo IVA ordine e totale;
- righe prodotto con quantità, prezzo IVA esclusa e aliquota IVA;
- righe sconto come righe negative;
- riga spedizione quando la spedizione IVA esclusa è positiva;
- riferimento ordine in note a piè di pagina e oggetto;
- tipo documento Fattura24 selezionato;
- Numerator ID e Template ID Fattura24 opzionali.
- numero documento Fattura24 personalizzato opzionale.

Per gli indirizzi di fatturazione italiani, il codice destinatario viene letto da `address.sdi` quando disponibile e usa `0000000` come fallback. Per gli indirizzi non italiani, il codice destinatario è `XXXXXXX`. La PEC viene letta da `address.pec` quando disponibile.

## Pannello ordine back office

La pagina dettaglio ordine mostra:

- regole documento abilitate e relativi ID stato trigger;
- righe storico documento per l'ordine;
- stato documento del modulo per ogni tipo documento tracciato;
- request ID Fattura24;
- ID documento Fattura24 quando disponibile;
- risposta API Fattura24 quando disponibile;
- messaggio di errore quando disponibile;
- numero di tentativi;
- data ultimo aggiornamento;
- azione `Send or retry` per ogni regola documento abilitata.

L'azione di retry usa un token legato al dipendente e forza un nuovo tentativo `SaveDocument` per il tipo documento configurato selezionato.

## Database

Il modulo possiede una tabella: `<prefix>tecfattura24_document`.

| Colonna | Scopo |
|---|---|
| `id_tecfattura24_document` | Chiave primaria. |
| `id_order` | ID ordine PrestaShop. |
| `id_shop` | ID shop. |
| `id_order_state` | Stato che ha attivato o ritentato l'invio. |
| `document_type` | Tipo documento Fattura24. |
| `id_request` | Request ID idempotente Fattura24. |
| `doc_id` | ID documento Fattura24 restituito dall'API. |
| `status` | `pending`, `sent` oppure `error`. |
| `api_response` | Risposta API Fattura24 grezza per invii riusciti e risposte fallite che non contengono un ID documento. |
| `error_message` | Ultimo messaggio di errore. |
| `attempts` | Numero di tentativi di invio. |
| `date_add` | Data creazione. |
| `date_upd` | Data ultimo aggiornamento. |

La tabella ha una chiave univoca su `id_order`, `document_type` e `id_shop`.

## Chiavi di configurazione

| Chiave | Default | Scopo |
|---|---:|---|
| `TECFATTURA24_API_KEY` | vuoto | Chiave API Fattura24. |
| `TECFATTURA24_DOCUMENT_RULES` | vuoto | Regole documento JSON indicizzate per tipo documento Fattura24. |
| `TECFATTURA24_TRIGGER_STATE` | `0` | Stato ordine legacy usato solo quando non sono salvate regole documento. |
| `TECFATTURA24_DOCUMENT_TYPE` | `FE` | Tipo documento legacy usato solo quando non sono salvate regole documento. |
| `TECFATTURA24_SEND_EMAIL` | `0` | Flag email legacy usato solo quando non sono salvate regole documento. |
| `TECFATTURA24_PAID_STATUS` | `0` | Flag pagato legacy usato solo quando non sono salvate regole documento. |
| `TECFATTURA24_ALLOW_ZERO` | `0` | Flag ordini a totale zero legacy usato solo quando non sono salvate regole documento. |
| `TECFATTURA24_ID_NUMERATOR` | vuoto | Numerator ID Fattura24 legacy usato solo quando non sono salvate regole documento. |
| `TECFATTURA24_ID_TEMPLATE` | vuoto | Template ID Fattura24 legacy usato solo quando non sono salvate regole documento. |
| `TECFATTURA24_TIMEOUT` | `60` | Timeout API Fattura24 in secondi. |
| `TECFATTURA24_DEBUG` | `0` | Abilita il logging debug. |
| `TECFATTURA24_TEST_KEY` | vuoto | Timestamp e stato HTTP dell'ultimo test chiave API. |

## Regole API Fattura24

Prima di usare il modulo in produzione, consulta il regolamento API e il regolamento e-commerce di Fattura24:

- https://www.fattura24.com/documentazione-legale/regolamento-api/
- https://www.fattura24.com/documentazione-legale/regolamento-ecommerce/

Il merchant resta responsabile della verifica dei dati di fatturazione, della correttezza fiscale, dello stato documento Fattura24 e degli esiti SDI.

## Note di sviluppo

- Mantieni il modulo compatibile con PrestaShop 1.7.8.11, 8.x e 9.x.
- Mantieni i percorsi codice sicuri per il legacy salvo che un futuro percorso moderno sia esplicitamente protetto da `_PS_VERSION_`.
- Non esporre la chiave API Fattura24 in JavaScript, log, URL, template o diagnostica.
- Mantieni l'HTML nei template Smarty, non in stringhe PHP.
- Mantieni il testo pubblico del modulo in inglese; le traduzioni italiane vanno aggiunte tramite il sistema di traduzione.
