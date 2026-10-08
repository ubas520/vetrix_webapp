# ERD data-store numbering

Source: the user-supplied `erd (2).png`, containing 17 tables. The image does not assign D-numbers; the following numbering is assigned for the diagrams and used consistently on every role page.

| DFD store | Exact ERD table name |
| --- | --- |
| D1 | users |
| D2 | pets |
| D3 | appointments |
| D4 | medical_records |
| D5 | vaccinations |
| D6 | edit_request |
| D7 | pet_update_logs |
| D8 | qr_tokens |
| D9 | inventory_items |
| D10 | inventory_movements |
| D11 | pos_transactions |
| D12 | pos_transaction_items |
| D13 | notifications |
| D14 | feedback |
| D15 | account_verification_tokens |
| D16 | email_outbox |
| D17 | audit_logs |

Each store represents one table. Repeated store symbols with the same D-number refer to that same table, not additional tables. Roles use only their relevant subset: Administrator 17, Veterinarian 6, Staff 9, Client 13 unique tables.

## Scope

These are ERD-aligned logical design views, not a statement that the current PHP schema matches this image. The image's spelling `edit_request` and its `pet_update_logs` table are retained deliberately, even though the current application source differs. No application schema or database has been modified.

The ERD omits workforce availability, mobile API tokens, product-order/payment-proof tables, POS receipts and restock-alert tables. The diagrams do not invent those stores. The client product process therefore shows browsing and purchase-history access using `inventory_items`, `pos_transactions` and `pos_transaction_items`; it is an ERD-based design view, not verification of an implemented client purchase-history endpoint.

The simple process groupings and logical handoffs are retained. A read or write arrow is a design-level description of what that process consumes or produces. Client approval, clinical editing and sale entry are performed by the appropriate clinic roles, not by the client. Routine technical flows are omitted to keep the views readable.

## Files

- `VETRIX-School-DFD.drawio`: all four role pages.
- `administrator-school.drawio`, `veterinarian-school.drawio`, `staff-school.drawio`, `client-school.drawio`: individual editable pages.
- `*-erd.png`: current preview images.
- `erd-role-drawio.cjs`: ERD names, process definitions and diagram layout.

Earlier `*-simple.png`, unqualified PNG/SVG files and the older detailed Markdown diagrams describe previous versions. Use the editable files and `*-erd.png` previews listed above for the ERD-aligned revision.
