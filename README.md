# SmartMove Transport Solutions

SmartMove is a transportation management web application developed for the NIBM Data Management 2 group coursework.

The application uses Oracle for structured operational data and MongoDB for document metadata, passenger feedback, announcements and community discussions.

## Technologies

- PHP, HTML, CSS and JavaScript
- Oracle Database XE — XEPDB1
- MongoDB
- XAMPP / Apache

## Features

### Admin
- Vehicle, driver and route management
- Trip scheduling
- Driver registration approvals
- Maintenance management
- Revenue and operational reports
- Announcement publishing and review moderation

### Passenger
- Registration and login
- Trip search and seat selection
- Booking history and eligible booking cancellation
- Payment recording
- Reviews for completed trips
- Travel announcements
- Community discussions and replies

### Driver
- Assigned trips and duty status updates
- Trip completion
- Vehicle document upload and download
- Maintenance recording

## Database Implementation

### Oracle

The relational database includes:

- PASSENGER
- VEHICLE
- DRIVER
- ROUTE
- TRIP
- BOOKING
- PAYMENT
- MAINTENANCE
- SYSTEM_USERS
- DRIVER_REGISTRATION_REQUESTS

Primary keys, foreign keys, constraints and indexes support data integrity. Booking records reserve seats, and a unique index prevents duplicate allocated seats for the same trip.

### MongoDB

| Collection | Purpose |
|---|---|
| vehicle_documents | Vehicle document metadata and file/image paths |
| passenger_reviews | Passenger ratings and feedback |
| travel_announcements | Travel notices and announcements |
| discussion_threads | Community discussions and replies |
| vehicle_maintenances | Maintenance information linked to Oracle records |
| activities | Application activity logs |

The databases are linked using IDs such as passenger_id, trip_id, vehicle_id and maintenance_id.

## Requirements

- Oracle Database XE with the XEPDB1 service
- MongoDB server
- XAMPP with PHP 8.2 or a compatible version
- PHP PDO_OCI extension and compatible Oracle client libraries
- PHP MongoDB extension
- PHP mbstring extension
- SQL Developer or SQL*Plus
- MongoDB Shell or a compatible MongoDB client

## Installation

### 1. Download the application

Download or clone the repository and place the application in:

```text
C:\xampp\htdocs\SmartMoveDB
```

Ensure index.php is directly inside this folder.

### 2. Configure Oracle

Create a SMARTMOVE database user in XEPDB1 with the privileges required by the setup script and sufficient tablespace quota.

Connect as SMARTMOVE and run:

```text
database/oracle/oracle_setup.sql
```

For optional sample data, run:

```text
database/oracle/oracle_demo_data.sql
```

These scripts are for a new environment. Do not run them against an existing working schema.

### 3. Configure MongoDB

Start MongoDB and run:

```text
database/Mongodb/mongodb_setup.js
```

For optional sample data, run:

```text
database/Mongodb/mongodb_demo_data.js
```

Use the matching Oracle demo data because MongoDB sample IDs refer to Oracle records.

Do not rerun fresh setup or demo scripts on an existing working database.

### 4. Configure application connections

Set the connection details in config.php for your local installation:

| Setting | Value |
|---|---|
| Oracle host | localhost |
| Oracle port | 1521 |
| Oracle service | XEPDB1 |
| Oracle username | SMARTMOVE |
| Oracle password | Your local database password |
| MongoDB URI | mongodb://localhost:27017 |
| MongoDB database | SmartMoveDB |

If the repository provides a config.local.php mechanism, follow its example configuration instead.

Do not publish real database passwords.

### 5. Configure document storage

Create:

```text
C:\xampp\SmartMoveDB_private\documents
```

Allow the Apache service account to read and write this folder.

SMARTMOVE_DOCUMENT_DIR can be used to specify another storage location.

MongoDB stores document metadata and paths. PDF files are stored separately. Sample paths do not create physical files.

### 6. Run the application

Start Apache, Oracle and MongoDB, then open:

```text
http://localhost/SmartMoveDB/
```

## PL/SQL Business Logic and Reports

The Oracle implementation includes procedures, functions, cursors and exception handling for:

- Revenue calculation for a selected period
- Vehicle maintenance cost calculation
- Available seat calculation
- Route booking usage
- Passenger travel history
- Driver schedules
- Driver duty status updates
- Booking cancellation
- Vehicle maintenance reports
- Payment channel analysis

## MongoDB Query Demonstrations

The query demonstrations cover:

- Passenger reviews for a selected route
- Vehicles ranked by average rating
- Drivers ranked by average rating
- Keyword searches in feedback
- Vehicle documents and multimedia references
- Route announcements
- Discussion threads

## API

API requests require a logged-in session.

| Action | Method | Access |
|---|---|---|
| get_earnings | GET | Admin |
| search_feedback | GET | Admin, Passenger or Driver |
| cancel_booking | POST | Passenger |

Revenue example:

```text
http://localhost/SmartMoveDB/api.php?action=get_earnings&from=2026-01-01&to=2026-12-31
```

Feedback search example:

```text
http://localhost/SmartMoveDB/api.php?action=search_feedback&keyword=delay
```

Cancellation accepts JSON containing booking_id and csrf_token. The passenger ID is taken from the session.

cancel_ticket remains available as a compatibility alias.

## Security and Data Integrity

- Password hashing
- Role-based access checks
- CSRF validation for supported write actions
- Prepared Oracle statements
- Passenger ownership checks for booking cancellation
- HTML escaping for displayed content
- Transaction handling and row locking for booking operations
- Unique indexes for allocated seats and review identifiers
- Restricted vehicle document download handling

## Payment Scope

Card payments are simulated for coursework demonstration and do not process real money.

Cash payment requests are recorded for the application's payment workflow.

## Oracle Backup and Restore

Oracle Data Pump export and restore were tested locally.

### Backup

Run in Windows Command Prompt:

```bat
expdp SCHEMAS=SMARTMOVE DIRECTORY=DATA_PUMP_DIR DUMPFILE=smartmove_backup_02.dmp LOGFILE=smartmove_backup_02.log
```

When prompted for the username:

```text
SMARTMOVE@"127.0.0.1:1521/XEPDB1"
```

Enter the SMARTMOVE database password when prompted.

The database user needs READ and WRITE privileges on DATA_PUMP_DIR. Dump and log files are written to the server-side path represented by that directory object.

### Restore Test

The backup was imported into a separate SMARTMOVE_RESTORE_TEST schema in XEPDB1.

Before importing, create the target user with a private password, CREATE SESSION privilege and sufficient quota on the USERS tablespace.

Run in Windows Command Prompt:

```bat
impdp SCHEMAS=SMARTMOVE DIRECTORY=DATA_PUMP_DIR DUMPFILE=smartmove_backup_02.dmp LOGFILE=smartmove_restore_test_01.log REMAP_SCHEMA=SMARTMOVE:SMARTMOVE_RESTORE_TEST EXCLUDE=USER,GRANT
```

When prompted for the username:

```text
SYSTEM@"127.0.0.1:1521/XEPDB1"
```

Enter the SYSTEM password when prompted.

REMAP_SCHEMA directs imported objects into the test schema. This restore test does not replace the application's SMARTMOVE schema.

### Verification

Export and import completed successfully. The restore verification checked:

| Table | Backup row count |
|---|---:|
| PASSENGER | 8 |
| BOOKING | 10 |
| PAYMENT | 7 |
| MAINTENANCE | 12 |

Invalid objects were also checked in the restored schema.

Retain export/import logs and verification screenshots as evidence. Keep dump files private because they contain application data and password hashes.

MongoDB backup and restore have not been tested. The Oracle export does not include MongoDB data or separately stored vehicle document files.

## Verified API and Announcement Checks

- The revenue API returned 10200 for the tested 2026 date range.
- Searching feedback for "delay" returned a matching review.
- Announcements published for ALL were displayed to passengers.

These results depend on the local dataset.

## Known Limitations

- Card payments are demonstrations.
- Discussions refresh when the page reloads.
- The passenger forum has no editing, deletion or admin moderation interface.
- The forum displays the latest 20 discussions and allows up to 100 replies per thread.
- MongoDB backup and restore verification remain outstanding.

## Team Contributions

Replace the placeholders with the actual team details and contributions.

| Member | Student ID | Contribution |
|---|---|---|
| S.S.Kaushani|GAHNDSE261F-018 | Passenger module and MongoDB integration |
| P.P.C.Pabasara |GAHNDSE261F-016| Admin module |
| Farah Mihsan | GAHNDSE261F-014 | Driver module |
| B.G.T.Upendra|GAHNDSE261F-017| Maintenance module |

## Academic Context

Developed for the NIBM Data Management 2 group coursework. Each member should understand and demonstrate their contribution during the presentation and viva.
