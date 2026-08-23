# GUS BIR protocol fixtures

These fixtures are independently authored test data for the native BIR 1.2
client. They are not copied from `gusapi/gusapi`, and they are not recordings
of production or sandbox traffic.

## Sources

The wire structure, namespaces, operation names, result element names, report
field names, and error codes were derived from the official GUS materials:

- [GUS API REGON portal](https://api.stat.gov.pl/Home/RegonApi)
- [BIR 1.2 documentation archive, documentation release 1.4](https://api.stat.gov.pl/Content/files/regon/GUS-Regon-UslugaBIRver1.2-dokumentacjaVer1.4.zip)
- `UslugaBIRzewnPubl-ver11-test.wsdl` and the BIR 1.1/1.2 XSD files contained
  in that archive
- BIR 1.2 technical instruction, internal document version 1.21 dated
  2025-12-31, contained in that archive

The fixture payloads, company names, identifiers, dates, explanatory messages,
MIME boundaries, and SOAP fault details were written specifically for this
repository. Official example payloads and third-party fixtures were not copied.

## Safety and data policy

- All entity names and addresses are fictional.
- Identifiers are synthetic and may deliberately fail official checksums. They
  must never be sent to the live service.
- The successful login fixture uses the synthetic 20-character SID
  `fixture-session-0001`.
- No API key, real SID, personal data, or raw service capture is stored here.
- Error descriptions are short repository-authored paraphrases. Only protocol
  field names and documented numeric codes match the GUS contract.
- `security/xxe.xml` is intentionally hostile input. A decoder must reject it
  without resolving its external entity.

## Layout

- `inner/` contains the XML string returned inside report and search result
  elements.
- `soap/` contains complete SOAP 1.2 response envelopes. Nested XML is held in
  CDATA, matching a documented response representation.
- `mime/` contains complete standalone MIME entities, including their top-level
  MIME headers. Tests should split the headers from the body when the transport
  exposes HTTP `Content-Type` separately.
- `security/` contains deliberately invalid or unsafe parser inputs.

The `root-not-first.multipart` fixture identifies the SOAP root part through
the MIME `start` parameter. A decoder must not assume that the first MIME part
is the SOAP envelope.
