# iSmile documents and design archive

This folder contains project reports, integration proposals, artwork, and design
previews published to the repository on 28 October 2026. Dated reports describe
the project at the time they were written; their launch status, test counts,
prices, and outstanding actions may differ from the current website.

The preview HTML files and screenshots are design references. Editing or opening
them does not change the production registration, sponsor, or payment flows.

## Integration proposals

- [Payment and check-in API / webhook diagram](iSmile-Payment-Checkin-API-Webhook-Diagram.pdf)
- [Partner integration next steps](iSmile-Partner-Integration-Next-Steps.pdf)
- [Integration overview](iSmile-Partner-Integration-Diagram.png)
- [Example messages and testing details](iSmile-Partner-Integration-Details.png)

The accompanying `build-integration-diagram.py` and `build-partner-next-steps.py`
scripts generate the integration documents with PyMuPDF.

## Design previews and artwork

- [VIP design options](iSmile-VIP-Design-Options.pdf) and [HTML preview](iSmile-VIP-Design-Options.html)
- [VIP experience HTML preview](iSmile-VIP-Experience.html)
- [VIP color animation HTML preview](iSmile-VIP-Color-Animation.html)
- [VIP experience screenshots and review PDF](vip-experience-final/)
- [Purple and gold VIP option](vip-gold/)
- [Sponsor registration design review](sponsor-flow-design-review/)
- [Sponsor artwork, source images, and export notes](dentstar-sponsor-preview/README.md)
- [Speaker portraits and responsive previews](speakers-preview-20261028/)

Download HTML previews together with this repository so their relative image,
stylesheet, and script references can resolve. The `build-vip-*.py` scripts are
the associated preview generators.

## Example reports and emails

- [Ticket and lunch payment examples](payment-report-preview/README.md)
- [Registration email and sample ticket](iSmile-registration-email-preview.pdf)
- [Certificate email preview](iSmile-certificate-email-preview.pdf)
- [Sample participation certificate](ismile-certificate-preview.pdf)

These examples contain fictitious guests and sample references. Their QR codes,
ticket numbers, amounts, and exchange rates are demonstration data, not valid
admission credentials or current pricing. No real payment or email was sent to
create the examples.

## Historical reports

- [Backend status, 28 September 2026](ismile-backend-status-2026-09-28.html)
- [Database guide, 6 October 2026](ismile-database-guide-2026-10-06.pdf) and [HTML version](ismile-database-guide-2026-10-06.html)
- [Local review, 6 October 2026](prepush-review-2026-10-06.md)
- [Website and database test report, 7 October 2026](iSmile-test-report-2026-10-07.pdf)
- [Security incident report, 27 September 2026](ismile-security-incident-2026-09-27.html)

The incident report is a historical record of the exposure and fix documented on
that date. Its list of follow-up actions is not a current audit of server secrets
or their rotation status. Credentials and private application storage are not
included in this archive.
