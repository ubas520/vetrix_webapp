# Simplified editable role diagrams

**Current revision:** The editable files now use the exact tables from the supplied ERD instead of the aggregate D0 described below. See [ERD data-store numbering](ERD-DATA-STORES.md). Current previews are `*-erd.png`. The following notes describe the earlier aggregate-store version.

The Staff, Veterinarian and Client pages in `VETRIX-School-DFD.drawio` and their individual `*-school.drawio` files now contain three grouped processes each. The Administrator page retains its existing detailed view. `DATA_FLOW_DIAGRAMS.md` retains the detailed source reference.

These are overview diagrams. D0 is an aggregate logical store covering the existing Vetrix tables, not a new database table. The direct arrows summarize dependencies implemented using stored records. Repeated user boxes denote the same external user. Process numbers are local to these simplified views and have been reassigned where tasks were combined.

| Role | Process | Includes |
| --- | --- | --- |
| Staff | 3.1 Manage clients and pets | Client and pet registration, profile and QR retrieval |
| Staff | 3.2 Schedule visits | Booking, scheduling, assignment and confirmation |
| Staff | 3.3 Handle sales and stock | Orders, payments, receipts and inventory |
| Veterinarian | 2.1 Manage visits | Assigned appointments, availability and visit status |
| Veterinarian | 2.2 Manage pet health | Consultations, health review, medical records and prescriptions |
| Veterinarian | 2.3 Record vaccinations | Vaccination history, administration and due dates |
| Client | 4.1 Manage account and pets | Account and pet submissions, profiles and review status |
| Client | 4.2 Manage visits and care | Appointment requests, cancellation, care history, reminders and feedback |
| Client | 4.3 Order products | Catalog, orders, payment proofs and order status |

The client does not approve pets or author clinical records: the care process returns clinic-recorded information. Authentication, email delivery and routine audit flows are omitted from the overview.

Regenerate editable files with `node docs/diagrams/generate-drawio.cjs`. Optional previews use `render-png.ps1`; `*-simple.png` files show the simplified versions, while the older unqualified image names retain the previous detailed views.
