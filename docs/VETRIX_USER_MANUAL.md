# Vetrix User Manual

Simple guide for Admin, Staff, and Veterinarian users

Prepared from the app screens and forms in this project on 11 September 2026.

## 1. Start here — all users

Vetrix helps the clinic manage pet owners, pets, appointments, treatment records, products, and payments. Your account decides which menus you can use.

- **Admin:** manages accounts, approvals, clinic schedules, products, reports, and follow-ups.
- **Staff:** assists owners, registers pets, arranges visits, handles sales and pickups, and updates stock.
- **Veterinarian (Vet):** reviews pet health, completes visits, records treatment and prescriptions, and records vaccinations.

### Sign in

1. Open the Vetrix website address provided by your clinic. On the clinic computer hosting the app, this may be http://localhost/vetrix/.
2. Enter your **Email address** and **Password**.
3. Click **Sign in**. Your Dashboard opens.

Use the menu on the left to open a page. On a smaller screen, open the menu first. **Dashboard** takes you back to your starting page.

### Find and save information

1. Open the relevant menu, such as **Pets** or **Appointments**.
2. Type a name in the search box and click **Search**.
3. Use a status filter, such as **Pending** or **All**, to narrow the list. Clear the search or return to All if something is missing.
4. Open the record and check the owner and pet before making changes.
5. After saving, read the message on screen. If an error appears, correct it and save again.

**Show** changes how many records appear on a page. List and grid buttons change how the same records are displayed.

### Your account

- Open your account menu and choose **Profile** to view or update your details.
- Choose **Change password**, enter your current password, enter the new password twice, and click **Change password**. Use at least 10 characters, including an uppercase letter, a lowercase letter, a number, and a symbol.
- Open **Notifications** to read alerts. **Mark all read** marks the alerts as read; it does not finish the tasks mentioned in them.
- When finished, open your account menu and click **Sign out**, especially on a shared computer.

## 2. Staff guide

### A. Register a client (pet owner)

1. Open **Clients** and search for the owner first to avoid a duplicate.
2. If the owner is new, click **Create client**.
3. Enter the full name, email, phone, address, and temporary password requested by the form.
4. Click **Create pending client**.
5. Ask the Admin to review the account. The client must verify the emailed one-time code after approval.

An OTP is a one-time verification code. Client verification is separate from staff access to this clinic website.

### B. Register a walk-in pet

1. Open **Pets** and find **Add Walk-In Pet**.
2. Select the correct **Owner**.
3. Enter the pet name, type, breed, sex, birth date, weight in kilograms, and color or markings.
4. Enter allergies, critical notes, and owner notes. Confirm these with the owner; ask the Vet if health information is unclear.
5. Add a pet picture if available, then click **Submit for Verification**.
6. Ask the Admin to review the pet. It must be **Approved** before it can be booked for an appointment.

### C. Arrange an appointment

1. Open **Calendar** to check the date and available times.
2. To create a visit, select the date and choose **Add client appointment**.
3. Choose the owner and approved pet. Fill in the requested date, time, and other details shown, then click **Create appointment**. New Staff-created appointments start as Pending.
4. Open **Appointments** and open the appointment details.
5. Set the **Clinic schedule**, **Assign veterinarian**, and **Duration**. Set **Status** to **Approved - confirmed** when the visit is confirmed.
6. Add any instructions in **Message / notes for client** and click **Save & Notify**.
7. Check the saved date, time, assigned Vet, and status.

For an existing request, start at step 4. If a time conflicts with another booking or an unavailable period, choose another time or ask the Admin to resolve it.

### D. Process a counter sale

1. Open **Point of Sale**.
2. Find each product and click **Add to cart**. Use the plus or minus buttons to set the quantity.
3. Select the client, or **Walk-in / No linked client** where appropriate.
4. Check the items, quantities, and total with the customer.
5. Choose **Cash** or **QR payment**. For cash, enter **Cash Received**, check the change, and select the correct **Payment Status**.
6. For QR payment, confirm receipt of the payment before checkout. This option records the sale as paid.
7. Click **Checkout** once and check the result. Open **Receipts** to find the saved receipt.

Stock updates automatically for the sale. Do not enter another Stock out adjustment for the same sale. If checkout seems interrupted, check Recent transactions and Receipts before trying again.

### E. Handle a product order for pickup

1. Open **Product Orders**, select an order, and check the client, products, quantities, and payment method.
2. For GCash, open **View payment proof**. Check the reference, recipient, and exact amount received in the clinic GCash account.
3. Tick the confirmation box and click **Verify GCash payment** if the payment matches. If it does not, enter a clear note and click **Reject payment proof**.
4. Prepare the items and click **Mark ready for pickup** when available.
5. At pickup, confirm the client and hand over the correct products. For pay-at-clinic orders, collect the full amount, enter **Amount received (PHP)**, and tick the confirmation box.
6. Click **Complete pickup** or **Complete pickup and record payment**, then open **View receipt** when shown.

Use the order's pickup process to record its sale. Do not create a second counter sale for the same order. **Cancel order** is available only for certain unpaid orders.

To update the receiving account for product orders, open **GCash Settings**, enter the clinic account name and mobile number, verify the details, tick the confirmation box, and click **Save GCash details**.

### F. Update inventory

1. Open **Inventory**. Check **Low stock** and **Out of stock**.
2. To record a delivery or other stock change, click **Stock adjustment**.
3. Select the item. Choose **Stock in** to add stock or **Stock out** to remove stock.
4. Enter the quantity and a reason, such as Delivery, Damaged item, or Clinic use.
5. Click **Record adjustment** and check the new stock count.

For a new product, click **Add inventory item**, fill in its name, price, unit, initial stock, and reorder level, then save. The reorder level is the quantity at which the app starts showing a low-stock alert. Check clinic pricing with the Admin before changing it.

### G. Look up a pet using its QR code

1. Open **QR Token**.
2. Use the scan option if available, or enter the code in **Secure QR token**.
3. Click **Retrieve record** and check the pet and owner.

This QR code finds a pet record. The payment QR shown at checkout is used to receive money.

## 3. Veterinarian guide

### A. Review today's patients

1. Open **Dashboard** and review the clinical queue.
2. Open **Appointments** or **Calendar** to check the visit time and assignment.
3. Open **Health Monitoring** and **Pets** to review allergies and critical notes.
4. Open **Medical Records** to check previous visits, diagnoses, medicines, and follow-up notes.

You can see appointments assigned to you and unassigned appointments allowed by your account. Ask the Admin or Staff to correct an assignment when needed.

### B. Finish a visit and record the consultation

1. After the consultation, open the approved appointment in **Appointments**.
2. Click **Mark Completed** and confirm. The appointment must be approved before this action is available.
3. Click **Create Medical Record** for the completed visit.
4. Check the pet and visit date. Enter **Diagnosis**, **Treatment**, **Medicine and instructions**, and **Notes**.
5. Choose a **Note type**, such as General note, Improving / stable, Urgent concern, or Needs follow-up.
6. Click **Save** and check the saved record.

For a separate entry, open **Prescription** directly, or choose **Add consultation** from **Medical Records**. Saving a prescription entry also creates a medical record. Completing an appointment and saving its clinical record are separate steps.

### C. Record a vaccination

1. Open **Vaccinations** and use **Add Vaccine**.
2. Select the approved pet and enter **Vaccine Name**.
3. Enter the actual **Date Given** and the **Next Due Date**.
4. Add remarks and click **Save**. **Administered By** uses the logged-in Vet's name.
5. Check the record in the vaccination list.

Use **Due soon**, **Overdue**, and **Scheduled** to review follow-ups. On an overdue record, use **Send notification** when a follow-up is needed.

### D. Correct pet details or review a change

1. Open **Pets**, find the pet, and click **Edit**.
2. Correct the details available in the form and click **Save and Notify Client**.
3. For a submitted change request, open **Pet Change Reviews** instead.
4. Compare the current details with the requested change. Choose the appropriate approval or rejection reason, add a note if needed, and click **Approve** or **Reject**.

### E. Request time away

1. Open **Calendar**, choose **Staff and Vets**, then **Unavailable**.
2. Choose the date and open **Add unavailability**.
3. Enter the reason, start, and end. Use repeat options only if needed.
4. Click **Submit unavailability**.
5. Check back for the Admin's decision. A pending request is not yet approved time away.

Staff can also use their Calendar to submit an unavailability request.

## 4. Admin guide

### A. Approve and manage client accounts

1. Open **Clients**, find the client, and click **Open account**.
2. Check the name, contact details, and account information.
3. Click **Approve and issue OTP** when the details are acceptable.
4. Ask the client to check their email and finish verification. Use **Resend OTP** if needed.
5. To correct contact details, edit the form and click **Save client details**.

You can also use **Create client** to add a pending account. Approval and email verification are separate steps.

### B. Approve pets and requested changes

1. Open **Pets** and find a pet needing review.
2. Click **Review profile** and check the owner and pet information.
3. Choose the **Current verification status**: Approved, Pending, Rejected, or In-person confirmation.
4. Add an **Administrator note** explaining the decision and click **Update verification**.
5. To correct details yourself, choose **Edit full details** and then **Save verified changes**.

For an owner-submitted change, open **Pet Edit Requests**, read the old and requested details, select a reason, and click **Approve** or **Reject**. Review any appeal notes before deciding whether to **Return to review** or **Reject appeal**.

For a walk-in pet you have already checked, use **Add Walk-In Pet** on Pets and click **Save Approved Pet**.

### C. Manage appointments and the team calendar

Use **Appointments** to update one visit's status, date, time, Vet, and client instructions. Follow the scheduling steps in Staff section C.

1. Open **Calendar**, choose **Staff and Vets**, and open **Clinic Schedule**.
2. Choose a date and **Add clinic day schedule**.
3. Enter the date, time ranges, schedule types, and the Vets or Staff assigned to each range.
4. Set repeat days and an end date if needed, then click **Save clinic schedule**.
5. Under **Unavailable**, open pending requests and choose **Approve request** or **Deny request**.

Review any affected appointments when approving time away. Unavailability entered by an Admin is approved immediately.

### D. Manage clinic user accounts

1. Open **Staff Accounts**, **Veterinarians**, or **Administrators**.
2. Click the **Create** button for that role and complete the account form.
3. For an existing user, open the account, update the details, and click **Save account**.
4. Use **Deactivate** when access should stop. Use **Restore account** when access should return.

**Delete** removes the account from normal use while keeping linked clinic records. Read the confirmation carefully and choose the correct person before proceeding.

### E. Monitor stock, prices, and sales

- Use **Inventory** to add products, review low stock, update product details, and record stock adjustments. Follow Staff section F.
- Use **Point of Sale** to review products shown to cashiers and recent transactions.
- To set the QR image used for counter payments, click **Payment QR**, provide the clinic payment image, and click **Save payment QR**.

The Staff **GCash Settings** page sets the receiving account details for product orders. The Admin **Payment QR** option sets the image used at counter checkout.

### F. Send follow-ups and review feedback

1. Open **Notifications** and click **New notification**.
2. Select the recipient, type, and status. Enter a clear title and message.
3. Click **Save notification**.

Open **Vaccinations** to monitor upcoming and overdue vaccinations, then use **Send reminder** where needed. Open **Feedback** to review client comments. Email delivery depends on the clinic's email setup; if a message does not arrive, check with the person maintaining the app.

### G. Print reports or export records

1. Open **Reports and Export**.
2. Choose the report, such as Pet Medical Report, Appointment Report, Vaccination Due Report, or Client and Pet Directory.
3. Select the pet, dates, or other details requested and click **Open report**.
4. Check the report, then click **Print / Save PDF**.
5. In the browser's print window, choose your printer, or choose **Save as PDF** and save the file.

Use **CSV exports** when you need a file that opens in a spreadsheet. Use **Activity Logs** to check who performed an action and when. Admin **QR Token** also provides pet record retrieval, as described in Staff section G.

## 5. What the status words mean

- **Pending appointment:** waiting for a decision; not a confirmed booking.
- **Approved appointment:** confirmed visit.
- **Completed appointment:** the visit has finished. Check that the Vet has also saved the medical record.
- **Rejected appointment:** the request was declined.
- **Cancelled appointment:** the visit will no longer go ahead.
- **Approved pet:** checked and eligible for booking.
- **In-person confirmation:** the pet needs a clinic check before approval.
- **Inactive account:** access is turned off.
- **Placed order:** received and awaiting preparation or payment review.
- **Ready for pickup:** prepared for collection.
- **Under review payment:** proof has been submitted and needs checking.
- **Overdue vaccination:** the saved next due date has passed.

## 6. Common problems

### I cannot sign in

Check the email and password, including capital letters. Ask the Admin to check whether your account is active and help restore access. Do not use another person's account.

### A client or pet is missing

Clear the search and select All. Check the owner name and spelling. If a pet is missing from appointment choices, ask the Admin to check its approval status. If a client was just registered, check whether approval and verification are still pending.

### The appointment will not save

Read the message. Check the pet's approval, selected Vet, date, time, and duration. Another visit or unavailable period may overlap. **Save & Notify** also requires an actual change to the appointment.

### The verification code did not arrive

Ask the client to check spam or junk mail. The Admin should check the email address and use **Resend OTP**. If it still does not arrive, ask the person maintaining the app to check email delivery.

### I cannot add a product to the cart

Check whether it is unavailable or out of stock. Ask the Admin or responsible Staff member to check its stock and product details.

### The QR lookup or camera does not work

Check the code and try entering the secure token manually. Allow camera access if prompted. Ask for a current valid code if the app says it is invalid or expired.

### A photo will not upload

Follow the file type and size limits shown beside the upload field. Pet pictures accept JPG, PNG, or WEBP up to 3 MB. Try a smaller picture in an accepted format.

### A page or action is unavailable

Check that you are signed in with the right role. If the page itself will not open, tell the person maintaining the app which page failed and the message shown.

## 7. Daily checklists

### Staff

- Review today's appointments and pending requests.
- Check client and pet details before booking.
- Process orders, payments, and receipts accurately.
- Record deliveries and other stock changes.
- Sign out at the end of the shift.

### Veterinarian

- Review the clinical queue, allergies, and critical notes.
- Check previous records before the consultation.
- Mark finished visits completed and save their clinical records.
- Record vaccinations and next due dates.
- Review follow-ups and sign out.

### Admin

- Review pending client, pet, and change approvals.
- Check appointments, team schedules, and time-away requests.
- Review low stock, sales, and follow-ups.
- Check reports, feedback, or activity history as needed.
- Sign out when finished.
