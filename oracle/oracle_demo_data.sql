
SET SERVEROUTPUT ON;
SET DEFINE OFF;
WHENEVER SQLERROR EXIT SQL.SQLCODE ROLLBACK;
DECLARE
    total_rows NUMBER;
BEGIN
    IF SYS_CONTEXT('USERENV','SESSION_USER') <> 'SMARTMOVE'
       OR SYS_CONTEXT('USERENV','CON_NAME') <> 'XEPDB1' THEN
        RAISE_APPLICATION_ERROR(-20993, 'Connect as SMARTMOVE in XEPDB1 first.');
    END IF;
    SELECT SUM(n) INTO total_rows FROM (
        SELECT COUNT(*) n FROM PASSENGER UNION ALL SELECT COUNT(*) FROM VEHICLE
        UNION ALL SELECT COUNT(*) FROM DRIVER UNION ALL SELECT COUNT(*) FROM ROUTE
        UNION ALL SELECT COUNT(*) FROM TRIP UNION ALL SELECT COUNT(*) FROM BOOKING
        UNION ALL SELECT COUNT(*) FROM PAYMENT UNION ALL SELECT COUNT(*) FROM MAINTENANCE
        UNION ALL SELECT COUNT(*) FROM SYSTEM_USERS
        UNION ALL SELECT COUNT(*) FROM DRIVER_REGISTRATION_REQUESTS
    );
    IF total_rows > 0 THEN
        RAISE_APPLICATION_ERROR(-20994, 'Demo seed requires a newly created empty schema.');
    END IF;
END;
/


insert into Route(source, destination, distance_km)
values('Hiniduma', 'Sinharaje', 55.0);

insert into Route(source, destination, distance_km)
values('Veerapana', 'Mathara', 100.0);

insert into Route(source, destination, distance_km)
values('Galle', 'Colombo', 120.0);

insert into Route(source, destination, distance_km)
values('Matara', 'Galle', 45.0);

insert into Route(source, destination, distance_km)
values('Colombo', 'Kandy', 115.0);


-- Vehicle data

insert into Vehicle(plate_number, vehicle_type, capacity, status)
values('WB-5638', 'Bus', 45, 'Active');

insert into Vehicle(plate_number, vehicle_type, capacity, status)
values('NP-3423', 'Van', 12, 'Active');

insert into Vehicle(plate_number, vehicle_type, capacity, status)
values('SP-4521', 'Bus', 50, 'Active');

insert into Vehicle(plate_number, vehicle_type, capacity, status)
values('WP-7812', 'Van', 15, 'Maintenance');

insert into Vehicle(plate_number, vehicle_type, capacity, status)
values('CP-2345', 'Bus', 40, 'Active');


-- Driver data

insert into Driver(driver_name, license_number, phone, status)
values('Nuwan Chamara', 'B1234444', '0781000001', 'Active');

insert into Driver(driver_name, license_number, phone, status)
values('Gayan Silva', 'B1238655', '0781000002', 'Active');

insert into Driver(driver_name, license_number, phone, status)
values('Kasun Perera', 'B1237777', '0771000003', 'Active');

insert into Driver(driver_name, license_number, phone, status)
values('Amila Fernando', 'B1238888', '0761000004', 'On Trip');

insert into Driver(driver_name, license_number, phone, status)
values('Sahan Madushan', 'B1239999', '0751000005', 'On Leave');


-- Passenger data

insert into Passenger(first_name, last_name, email, phone)
values('Nuwan', 'Samagipura', 'nuwan@gmail.com', '0771000001');

insert into Passenger(first_name, last_name, email, phone)
values('Kasuni', 'Perera', 'kasuni@gmail.com', '0772000002');

insert into Passenger(first_name, last_name, email, phone)
values('Tharindu', 'Silva', 'tharindu@gmail.com', '0762000003');

insert into Passenger(first_name, last_name, email, phone)
values('Pabasara', 'Fernando', 'pabasara@gmail.com', '0752000004');

insert into Passenger(first_name, last_name, email, phone)
values('Sachini', 'Dilshan', 'sachini@gmail.com', '0742000005');


-- Trip data

insert into Trip(route_id, vehicle_id, driver_id, departure_time, arrival_time, fare_amount)
values(1, 1, 1, TIMESTAMP '2026-10-10 06:00:00', TIMESTAMP '2026-10-10 06:00:00' + INTERVAL '2' HOUR, 1500.00);

insert into Trip(route_id, vehicle_id, driver_id, departure_time, arrival_time, fare_amount)
values(2, 2, 2, TIMESTAMP '2026-10-10 06:00:00', TIMESTAMP '2026-10-10 06:00:00' + INTERVAL '3' HOUR, 2500.00);

insert into Trip(route_id, vehicle_id, driver_id, departure_time, arrival_time, fare_amount)
values(3, 3, 3, TIMESTAMP '2026-10-07 06:00:00', TIMESTAMP '2026-10-07 06:00:00' + INTERVAL '2' HOUR, 2000.00);

insert into Trip(route_id, vehicle_id, driver_id, departure_time, arrival_time, fare_amount)
values(4, 1, 4, TIMESTAMP '2026-10-11 06:00:00', TIMESTAMP '2026-10-11 06:00:00' + INTERVAL '1' HOUR, 1000.00);

insert into Trip(route_id, vehicle_id, driver_id, departure_time, arrival_time, fare_amount)
values(5, 5, 2, TIMESTAMP '2026-10-11 09:00:00', TIMESTAMP '2026-10-11 09:00:00' + INTERVAL '3' HOUR, 2200.00);


-- Booking data

insert into Booking(trip_id, passenger_id, seat_number, status)
values(1, 1, 'A1', 'Confirmed');

insert into Booking(trip_id, passenger_id, seat_number, status)
values(2, 2, 'B2', 'Confirmed');

insert into Booking(trip_id, passenger_id, seat_number, status)
values(3, 3, 'C3', 'Completed');

insert into Booking(trip_id, passenger_id, seat_number, status)
values(4, 4, 'D4', 'Pending');

insert into Booking(trip_id, passenger_id, seat_number, status)
values(5, 5, 'E5', 'Confirmed');


-- Payment data

insert into Payment(Booking_ID, amount, payment_method)
values(1, 1500.00, 'Card');

insert into Payment(Booking_ID, amount, payment_method)
values(2, 2500.00, 'Cash');

insert into Payment(Booking_ID, amount, payment_method)
values(3, 2000.00, 'Online Transfer');

insert into Payment(Booking_ID, amount, payment_method)
values(4, 1000.00, 'Card');

insert into Payment(Booking_ID, amount, payment_method)
values(5, 2200.00, 'Online Transfer');


-- Maintenance data

insert into Maintenance(vehicle_id, maintenance_date, description, cost)
values(1, DATE '2026-09-10', 'Engine service', 25000.00);

insert into Maintenance(vehicle_id, maintenance_date, description, cost)
values(2, DATE '2026-09-15', 'Oil change', 12000.00);

insert into Maintenance(vehicle_id, maintenance_date, description, cost)
values(3, DATE '2026-09-20', 'Brake inspection', 18000.00);

insert into Maintenance(vehicle_id, maintenance_date, description, cost)
values(4, DATE '2026-09-25', 'Full vehicle service', 30000.00);

insert into Maintenance(vehicle_id, maintenance_date, description, cost)
values(5, DATE '2026-09-28', 'Tyre replacement', 45000.00);



-- Fixed dates make the annual earnings demonstration reproducible.
UPDATE Booking SET Booking_Date = DATE '2026-10-01';
UPDATE Payment SET Payment_Date = TIMESTAMP '2026-10-01 12:00:00';

INSERT INTO System_Users (Username, Password_Hash, Role, Reference_ID)
VALUES ('admin_demo', '$2b$12$f859BeN5STUlsKTyTLC5VeRRePHZP4ov6t/3QQhH0XjMpdXSLZlCa', 'ADMIN', NULL);

INSERT INTO System_Users (Username, Password_Hash, Role, Reference_ID)
VALUES ('passenger_demo', '$2b$12$f859BeN5STUlsKTyTLC5VeRRePHZP4ov6t/3QQhH0XjMpdXSLZlCa', 'PASSENGER', 1);

INSERT INTO System_Users (Username, Password_Hash, Role, Reference_ID)
VALUES ('driver_demo', '$2b$12$f859BeN5STUlsKTyTLC5VeRRePHZP4ov6t/3QQhH0XjMpdXSLZlCa', 'DRIVER', 1);

COMMIT;

