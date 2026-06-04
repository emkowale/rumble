# Rumble Project Context

Rumble is a web-based/mobile-friendly internal workflow system for The Bear Traxs.

Goal:
Replace email-based task tracking with a conveyor-belt workflow for quotes, orders, art, digitizing, invoicing, production prep, and work order creation.

Current systems:
- Traxs: existing work order system
- FreshBooks: invoicing
- Huffer: commission calculations
- WooCommerce: existing order/customer ecosystem
- Dynamic Mockups is NOT current and should not be used

Core workflow:
Paper quote/order from outside sales
→ inside sales intake
→ quote or order entry
→ quote approval if needed
→ FreshBooks invoice/payment handling
→ art department review
→ mockup or art cleanup if needed
→ DTG / screen print / embroidery routing
→ embroidery digitizing if needed
→ print-ready art
→ Traxs work order
→ production
→ completion/reporting

Main design concept:
Rumble is a conveyor-belt workflow system, not a generic task list.

Important requirements:
- mobile-friendly
- web-based
- department queues
- assigned users
- due dates
- status tracking
- comments instead of email
- file/art attachments
- stage timestamps
- reporting on slow stages and slow workers
- ability to see what is overdue
- ability to know when each department finishes work
- eventual integration with Traxs, FreshBooks, and Huffer

Initial build priority:
Start by designing screens before writing backend code.

Initial screens:
1. Main Dashboard
2. Job Detail Screen
3. Intake Screen
4. Inside Sales Queue
5. Quote Queue
6. Invoice/AP Queue
7. Art Queue
8. Digitizing Queue
9. Production Prep Queue
10. Reporting Dashboard

Architectural direction:
Build as a WordPress plugin or Traxs module.
Do not build around email.
Do not build as generic Google Tasks.
Use workflow stages and state transitions.