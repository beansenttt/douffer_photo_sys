# Douffer PhotoSys: Data Flow Diagrams (DFD)

Generated from the actual implementation of **Douffer PhotoSys** per `PROMPT.md` §13.4.

---

## 1. Context Diagram

Shows the system boundary and the external entities (**Client**, **Studio Owner / Admin**, **Studio Staff**, and **On-Call Crew**) interacting with **Douffer PhotoSys**.

```mermaid
flowchart LR
    Client["External Entity: Client"]
    Owner["External Entity: Studio Owner / Admin"]
    Staff["External Entity: Studio Staff"]
    Crew["External Entity: On-Call Crew"]
    System(("0.0\nDouffer PhotoSys\nManagement & Booking System"))

    Client -- "Date Check, Booking Details, Privacy & Terms Consent,\nGCash Ref No. & Screenshot, Chat Inquiries & Replies" --> System
    System -- "Package Catalog, Date Availability, Reference Code (DPS-YYYY-NNNN),\nBooking Status & Confirmation (A8), Chat Messages" --> Client

    Owner -- "Payment Verification/Rejection, Direct Bookings, Reschedule/Cancel,\nCrew Assignments, Project Stage/Handover, Catalog/User/Settings/Backup Updates" --> System
    System -- "Dashboard Alerts, Verification Queue, Official Receipts (OR-YYYY-NNNN),\nSchedules, Project Tracker, Reports & CSV, 6-Stream Audit Logs (A21)" --> Owner

    Staff -- "Walk-in Bookings, Balance Payments, Crew & Project Updates,\nTemplate & In-App Messages (per granted permissions)" --> System
    System -- "Permitted Calendar, Ledger, Project Tracker & Studio Inbox Views" --> Staff

    Crew -- "Login Credentials" --> System
    System -- "Read-Only Assigned Events, Call Times, Venues,\nInclusions, Equipment Checklists & Crew-Visible Notes (A18)" --> Crew
```

---

## 2. DFD Level 0 (System Overview)

Decomposes the system into the core processes (**1.0 Verify Package & Date Availability**, **2.0 Stage Reservation**, **3.0 Verify Payment**, **4.0 Track Deliverables**, and **5.0 Manage In-App Messaging & Logs**) and core data stores (**D1–D5**).

```mermaid
flowchart TB
    Client["Client"]
    Admin["Studio Owner / Staff"]
    Crew["On-Call Crew"]

    P1(("1.0\nVerify Package &\nDate Availability\n(C1, C2, A3, A20)"))
    P2(("2.0\nStage Reservation &\nPolicy Changes\n(C3, C4, A5, A13)"))
    P3(("3.0\nVerify & Record\nPayments\n(A7, A8, A6)"))
    P4(("4.0\nAssign Crew &\nTrack Deliverables\n(A9, A10, A11, A18)"))
    P5(("5.0\nIn-App Messaging,\nReports & Audit Logs\n(C6, A12, A16, A19, A21)"))

    D1[("D1: Clients & Bookings\n(clients, client_inquiries,\nbookings, booking_changes)")]
    D2[("D2: Packages & Calendar\n(packages, addons, settings,\nblocked_dates, date_locks)")]
    D3[("D3: Payments & Receipts\n(payments, counters,\nstorage/receipts)")]
    D4[("D4: Projects, Crew & Gear\n(projects, project_history,\ncrew_members, crew_assignments,\nbooking_equipment, booking_notes)")]
    D5[("D5: Messaging & Audit Logs\n(conversations, messages,\nmessage_log, audit_logs, backup_runs)")]

    Client -- "Browse Catalog & Check Date" --> P1
    P1 -- "Available Dates & Package Rates" --> Client
    D2 <--> P1
    D1 --> P1

    Client -- "Booking Form + GCash Proof + Terms Consent" --> P2
    Admin -- "Direct Booking (A5) / Reschedule or Cancel (A13)" --> P2
    P2 -- "Reference Code (DPS-YYYY-NNNN) & Pending Status" --> Client
    P2 <--> D1
    P2 <--> D2
    P2 --> D3
    P2 -- "Auto-Create Thread & System Notice" --> D5

    Admin -- "Verify / Reject GCash Proof (A7) or Record Balance (A6)" --> P3
    D3 <--> P3
    P3 <--> D2
    P3 -- "Confirm Booking / Update Balance" --> D1
    P3 -- "Create Project Row on Confirmation" --> D4
    P3 -- "Official Receipt (OR-YYYY-NNNN) & Confirmation Sheet" --> Admin
    P3 -- "Confirmation View (C5/A8)" --> Client
    P3 -- "Payment System Message & Audit Log" --> D5

    Admin -- "Assign Crew (A9), Update Stage (A10), Log Folders & Release (A11)" --> P4
    P4 <--> D4
    P4 <--> D1
    P4 -- "Assigned Events, Call Time & Studio Notes (A18)" --> Crew
    P4 -- "Stage & Handover Logs" --> D5

    Client <--> P5
    Admin <--> P5
    P5 <--> D5
    D1 --> P5
    D3 --> P5
    D4 --> P5
```

---

## 3. DFD Level 1 — Detailed Process & Data Store Flows

Shows the step-by-step internal sub-processes for **1.0–4.0** plus the **In-App Messaging & Audit Trail** flows.

```mermaid
flowchart TD
    subgraph P1_Group["Process 1.0: Verify Package & Date Availability"]
        P1_1(("1.1 Load Active\nPackages & Add-ons"))
        P1_2(("1.2 Evaluate Date:\nPast / Blocked /\nConfirmed < Max"))
        P1_3(("1.3 Manage Blocked\nDates & Rules (A20)"))
    end

    subgraph P2_Group["Process 2.0: Stage Reservation & Policy Handling"]
        P2_1(("2.1 Validate Client,\nDate & Consent (C3)"))
        P2_2(("2.2 Compress & Store\nGCash Proof + Issue\nDPS-YYYY-NNNN (C4)"))
        P2_3(("2.3 Reschedule (BR-3)\nor Cancel & Forfeit\nDeposit (BR-2, A13)"))
    end

    subgraph P3_Group["Process 3.0: Verify Payment & Issue Receipt"]
        P3_1(("3.1 Check Duplicate\nGCash Ref (Risk 3)"))
        P3_2(("3.2 Lock Date Mutex\n(SELECT FOR UPDATE)\n& Re-check Capacity"))
        P3_3(("3.3 Issue OR-YYYY-NNNN,\nConfirm Booking &\nSpawn Project"))
        P3_4(("3.4 Reject Payment or\nRecord Balance (A6/A7)"))
    end

    subgraph P4_Group["Process 4.0: Track Crew & Deliverables"]
        P4_1(("4.1 Assign Crew &\nBlock Overlapping\nHours (A9 / T4)"))
        P4_2(("4.2 Advance Stages:\nBooked->Covered->\nEditing->Ready (A10)"))
        P4_3(("4.3 Log RAW/EDITED\nFolders, Backups &\nRelease Output (A11)"))
        P4_4(("4.4 Serve Crew Read-Only\nSchedule (A18 / T7)"))
    end

    subgraph P5_Group["Process 5.0: In-App Messaging & Forensic Audit Logging"]
        P5_1(("5.1 Pre-Booking Inquiry\nToken Chat & Booking\nThread (C6 / A19)"))
        P5_2(("5.2 Incremental Polling\n(since_id) & Sent/Seen\nReceipts (/api/*)"))
        P5_3(("5.3 Bilingual Templates\n(A12), Reports (A16) &\n6-Stream Logs (A21)"))
    end

    D1[("D1: Clients & Bookings")]
    D2[("D2: Packages & Calendar")]
    D3[("D3: Payments & Receipts")]
    D4[("D4: Projects & Deliverables")]
    D5[("D5: Chat & Audit Logs")]

    D2 --> P1_1
    D2 --> P1_2
    D1 --> P1_2
    P1_3 --> D2
    P1_3 --> D5

    P1_2 --> P2_1
    P2_1 --> P2_2
    P2_2 --> D1
    P2_2 --> D3
    P2_2 --> P5_1
    P2_3 <--> D1
    P2_3 <--> D2
    P2_3 --> D3
    P2_3 --> D5

    D3 --> P3_1
    P3_1 --> P3_2
    P3_2 <--> D2
    P3_2 --> P3_3
    P3_3 --> D1
    P3_3 --> D3
    P3_3 --> D4
    P3_3 --> P5_1
    P3_4 --> D3
    P3_4 --> P5_1

    P4_1 <--> D4
    P4_1 --> D5
    P4_2 <--> D4
    P4_2 --> P5_1
    P4_3 <--> D4
    P4_3 --> D1
    P4_3 --> D5
    D4 --> P4_4

    P5_1 <--> D5
    P5_2 <--> D5
    D1 --> P5_3
    D3 --> P5_3
    D4 --> P5_3
    P5_3 <--> D5
```
