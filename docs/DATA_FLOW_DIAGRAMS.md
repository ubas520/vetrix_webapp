# Vetrix — Data Flow Diagrams by Role

Four simplified Level 1 role views, based on the PHP website and mobile API source in this workspace.

**Version note:** This Markdown preserves the original source-code-based diagrams. The current editable diagrams have been revised to match the user's supplied ERD; their numbering and table names are documented in [ERD-DATA-STORES.md](diagrams/ERD-DATA-STORES.md).

Rectangles represent external users, rounded boxes represent processes, and cylinders represent logical data stores. Labeled arrows represent data, not the order of steps. Store IDs are shared across diagrams; each diagram shows the relevant portion of a store.

The Client/Pet Owner view follows `api/mobile/v1`. Client sign-in is blocked on the clinic website; client interactions here refer to the mobile API. Source inspection does not confirm a deployed mobile app.

Login/session validation, routine audit writes, file storage and email delivery are omitted to keep these role views readable. These are selected business-process views, not an exhaustive, balanced decomposition of a system context diagram.

## Administrator

```mermaid
flowchart LR
    A[Administrator]
    P1("1.1 Manage accounts")
    P2("1.2 Verify pets and edit requests")
    P3("1.3 Manage clinic schedules")
    P4("1.4 Manage inventory and sales")
    P5("1.5 Review reports and activity")
    D1[("D1 Accounts")]
    D2[("D2 Pets, edit requests and QR tokens")]
    D3[("D3 Appointments and availability")]
    D5[("D5 Inventory and movements")]
    D6[("D6 Sales and receipts")]
    D7[("D7 Notifications, feedback and audit logs")]
    A -->|Account details and approval decisions| P1
    D1 -->|Existing accounts| P1
    P1 -->|Account and status updates| D1
    P1 -->|Account status| A
    A -->|Pet verification and edit decisions| P2
    D1 -->|Client ID and owner details| P2
    D2 -->|Pet profiles and pending requests| P2
    P2 -->|Verified profiles and review results| D2
    P2 -->|Review confirmation| A
    A -->|Schedule details and vet assignments| P3
    D1 -->|Client and veterinarian details| P3
    D2 -->|Approved pet ID and owner ID| P3
    D3 -->|Bookings and availability| P3
    P3 -->|Schedules and booking decisions| D3
    P3 -->|Clinic calendar| A
    A -->|Product, stock and sale details| P4
    D1 -->|Client identity for sales| P4
    D5 -->|Product and stock data| P4
    P4 -->|Product and stock updates| D5
    P4 -->|Sale and receipt records| D6
    P4 -->|Stock and transaction results| A
    A -->|Report filters and review requests| P5
    D1 -->|Client and workforce data| P5
    D2 -->|Pet data| P5
    D3 -->|Appointment data| P5
    D6 -->|Sales data| P5
    D7 -->|Feedback and activity data| P5
    P5 -->|Reports, exports and activity views| A
```

## Veterinarian

```mermaid
flowchart LR
    V[Veterinarian]
    P1("2.1 Manage assigned visits and availability")
    P2("2.2 Record consultations and prescriptions")
    P3("2.3 Record vaccinations")
    P4("2.4 Review pet health and changes")
    D2[("D2 Pets and edit requests")]
    D3[("D3 Appointments and availability")]
    D4[("D4 Medical and vaccination records")]
    V -->|Availability and visit completion details| P1
    D3 -->|Assigned appointments and schedules| P1
    P1 -->|Availability and completed visit status| D3
    P1 -->|Schedule and appointment details| V
    V -->|Symptoms, diagnosis, treatment and prescription| P2
    D2 -->|Pet profile| P2
    D4 -->|Clinical history| P2
    P2 -->|Medical record and prescription| D4
    P2 -->|Consultation summary and prescription| V
    V -->|Vaccine, date given and next due date| P3
    D2 -->|Pet identity| P3
    D4 -->|Vaccination history| P3
    P3 -->|Vaccination record and next due date| D4
    P3 -->|Vaccination schedule| V
    V -->|Health notes and change review decisions| P4
    D2 -->|Pet details and requested changes| P4
    D4 -->|Health history| P4
    P4 -->|Pet health updates and review results| D2
    P4 -->|Updated health profile| V
    P1 -->|Assigned appointment and pet details| P2
    P4 -->|Pet profile and health notes| P2
    P4 -->|Pet identity and health information| P3
    P2 -->|Diagnosis, treatment and prescription history| P4
    P3 -->|Vaccination history and next due date| P4
```

## Staff

```mermaid
flowchart LR
    S[Staff]
    P1("3.1 Register clients and pets")
    P2("3.2 Schedule appointments")
    P3("3.3 Process orders and payments")
    P4("3.4 Manage inventory")
    P5("3.5 Retrieve pet records by QR")
    D1[("D1 Accounts")]
    D2[("D2 Pets and QR tokens")]
    D3[("D3 Appointments and availability")]
    D4[("D4 Clinical records")]
    D5[("D5 Inventory and movements")]
    D6[("D6 Orders, payments, sales and receipts")]
    S -->|Client and pet details| P1
    D1 -->|Existing client details| P1
    P1 -->|Client records| D1
    P1 -->|Pet profiles| D2
    P1 -->|Registration result| S
    S -->|Booking decision, date and assigned vet| P2
    D2 -->|Pet verification status| P2
    D3 -->|Requests, conflicts and availability| P2
    P2 -->|Appointment and assignment updates| D3
    P2 -->|Booking confirmation| S
    S -->|Sale details, payment review and order updates| P3
    D6 -->|Orders and payment proofs| P3
    D5 -->|Products, prices and stock| P3
    P3 -->|Order, payment, sale and receipt records| D6
    P3 -->|Stock adjustments and movement records| D5
    P3 -->|Receipt and order status| S
    S -->|Product details and stock movements| P4
    D5 -->|Current stock| P4
    P4 -->|Product, stock and movement updates| D5
    P4 -->|Stock levels and low-stock results| S
    S -->|Scanned QR token| P5
    D2 -->|Token validity and pet identity| P5
    D4 -->|Permitted clinical history| P5
    P5 -->|Pet record summary| S
    P1 -->|Client and pet details| P2
    P1 -->|Client details| P3
    P1 -->|Registered pet information| P5
    P4 -->|Products, prices and available stock| P3
    P3 -->|Stock deductions and cancellation returns| P4
```

## Client / Pet Owner — mobile API

```mermaid
flowchart LR
    C[Client / Pet Owner]
    P1("4.1 Register and manage account")
    P2("4.2 Register pets and request edits")
    P3("4.3 Request or cancel appointments")
    P4("4.4 Browse products and place orders")
    P5("4.5 View care records and submit feedback")
    D1[("D1 Accounts and verification tokens")]
    D2[("D2 Pets, edit requests and QR tokens")]
    D3[("D3 Appointments and availability")]
    D4[("D4 Medical and vaccination records")]
    D5[("D5 Inventory and movements")]
    D6[("D6 Orders and payment proofs")]
    D7[("D7 Notifications and feedback")]
    C -->|Registration, verification and profile details| P1
    D1 -->|Account and verification status| P1
    P1 -->|Registration and profile updates| D1
    P1 -->|Account status and profile| C
    C -->|Pet details, photo and requested changes| P2
    D2 -->|Owned pets and review status| P2
    P2 -->|Pet submissions and edit requests| D2
    P2 -->|Pet profiles, QR tokens and review status| C
    C -->|Pet, service, concern, date or cancellation| P3
    D2 -->|Ownership and pet approval| P3
    D3 -->|Booking and schedule data| P3
    P3 -->|Appointment request or cancellation| D3
    P3 -->|Appointment status and schedule| C
    C -->|Product query, order, cancellation or payment proof| P4
    D5 -->|Available products, prices and stock| P4
    D6 -->|Owned orders and payment status| P4
    P4 -->|Order and payment proof records| D6
    P4 -->|Stock reservations or releases| D5
    P4 -->|Catalog, order status and payment instructions| C
    C -->|Record request, read status or visit rating| P5
    D3 -->|Completed visit eligibility| P5
    D4 -->|Owned pets' medical and vaccination history| P5
    D7 -->|Notifications and previous feedback| P5
    P5 -->|Read status and visit feedback| D7
    P5 -->|Care history, reminders and feedback result| C
    P1 -->|Client ID and owner details| P2
    P1 -->|Client identity| P3
    P1 -->|Client details for orders| P4
    P1 -->|Client identity for record access| P5
    P2 -->|Pet ID, ownership and approval status| P3
    P2 -->|Owned pet IDs and profiles| P5
    P3 -->|Appointment ID and completed-visit status| P5
```

## Logical data store mapping

| Store | Source tables |
| --- | --- |
| D1 Accounts | users, account_verification_tokens, mobile_api_tokens |
| D2 Pet information | pets, edit_requests, qr_tokens |
| D3 Scheduling | appointments, staff_availability |
| D4 Clinical records | medical_records, vaccinations |
| D5 Inventory | inventory_items, inventory_movements, inventory_restock_alerts |
| D6 Orders and sales | product_orders, product_order_items, product_order_proofs, product_order_gcash_references, product_order_bank_references, product_order_recipients, product_payment_settings, product_payment_accounts, pos_transactions, pos_transaction_items, pos_receipts |
| D7 Communication and oversight | notifications, product_order_notifications, feedback, audit_logs, email_outbox |

## Interpretation notes

- Processes mediate all access to stored data. Users never connect directly to stores.
- Direct process-to-process arrows in the veterinarian, staff and client views summarize logical data dependencies. The application implements these dependencies through the shared stores also shown; these arrows do not assert direct function calls or an additional transfer mechanism.
- Shared stores connect the role views: client requests become clinic work; clinical entries become client-visible care history.
- Pet booking depends on ownership and approval. Scheduling also checks conflicts and workforce availability.
- Pet edit submissions are requests for review; they do not imply immediate approval.
- The order code reserves available stock and records inventory movements, and restores stock when applicable. Payment proofs are reviewed by staff.
- Medical and vaccination records are grouped logically in D4; a client's read access does not grant clinical editing permission.
- Product order and payment stores are defined in migrations and runtime schema helpers, beyond the original base SQL schema.

## Source references

- Role menus: `includes/admin_sidebar.php`, `includes/vet_sidebar.php`, `includes/staff_sidebar.php`.
- Website workflows: `admin/`, `vet/`, `staff/`.
- Client workflows: `api/mobile/v1/register.php`, `profile.php`, `pets.php`, `pet_edit_requests.php`, `appointments.php`, `orders.php`, `bootstrap.php`, `feedback.php`.
- Order and stock behavior: `includes/product_orders.php`, `includes/product_order_pos_checkout.php`.
- Schema: `vetrix.sql`, `migrations/product_orders.sql`, `migrations/payment_accounts.sql`.
