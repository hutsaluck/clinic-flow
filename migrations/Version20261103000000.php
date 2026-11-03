<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ClinicFlow: identity, tenancy, scheduling, booking constraints, billing and outbox';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'PostgreSQL required');
        $sql = <<<'SQL'
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE TABLE users (id UUID PRIMARY KEY, email VARCHAR(255) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, first_name VARCHAR(120) NOT NULL, last_name VARCHAR(120) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE' CHECK(status IN ('ACTIVE','DISABLED')), platform_admin BOOLEAN NOT NULL DEFAULT FALSE, created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE clinics (id UUID PRIMARY KEY, name VARCHAR(255) NOT NULL, slug VARCHAR(100) NOT NULL UNIQUE, timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Kyiv', currency CHAR(3) NOT NULL DEFAULT 'UAH', email VARCHAR(255), phone VARCHAR(30), address TEXT, status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE' CHECK(status IN ('ACTIVE','SUSPENDED')), booking_horizon_days INT NOT NULL DEFAULT 90 CHECK(booking_horizon_days BETWEEN 1 AND 365), cancellation_hours INT NOT NULL DEFAULT 0 CHECK(cancellation_hours >= 0), created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE clinic_memberships (id UUID PRIMARY KEY, clinic_id UUID NOT NULL REFERENCES clinics(id), user_id UUID NOT NULL REFERENCES users(id), role VARCHAR(30) NOT NULL CHECK(role IN ('CLINIC_ADMIN','RECEPTIONIST','DOCTOR')), status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE' CHECK(status IN ('ACTIVE','INACTIVE')), joined_at TIMESTAMPTZ NOT NULL DEFAULT now(), created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(), UNIQUE(clinic_id,user_id), UNIQUE(clinic_id,id));
CREATE TABLE patients (id UUID PRIMARY KEY, clinic_id UUID NOT NULL REFERENCES clinics(id), first_name VARCHAR(120) NOT NULL, last_name VARCHAR(120) NOT NULL, middle_name VARCHAR(120), phone_e164 VARCHAR(30), email VARCHAR(255), birth_date DATE, administrative_notes TEXT, archived_at TIMESTAMPTZ, created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(), UNIQUE(clinic_id,id), CHECK(phone_e164 IS NOT NULL OR email IS NOT NULL));
CREATE INDEX patients_name ON patients(clinic_id,last_name,first_name);
CREATE INDEX patients_phone ON patients(clinic_id,phone_e164);
CREATE INDEX patients_email ON patients(clinic_id,email);
CREATE TABLE services (id UUID PRIMARY KEY, clinic_id UUID NOT NULL REFERENCES clinics(id), name VARCHAR(255) NOT NULL, description TEXT, duration_minutes INT NOT NULL CHECK(duration_minutes BETWEEN 5 AND 480), price_minor BIGINT NOT NULL CHECK(price_minor >= 0), currency CHAR(3) NOT NULL, is_active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(), UNIQUE(clinic_id,id));
CREATE INDEX services_clinic ON services(clinic_id,is_active);
CREATE TABLE doctor_profiles (id UUID PRIMARY KEY, clinic_id UUID NOT NULL REFERENCES clinics(id), membership_id UUID, first_name VARCHAR(120) NOT NULL, last_name VARCHAR(120) NOT NULL, specialization VARCHAR(255) NOT NULL, description TEXT, is_active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(), UNIQUE(clinic_id,id), UNIQUE(membership_id), FOREIGN KEY(clinic_id,membership_id) REFERENCES clinic_memberships(clinic_id,id));
CREATE INDEX doctors_clinic ON doctor_profiles(clinic_id,is_active);
CREATE TABLE doctor_services (clinic_id UUID NOT NULL, doctor_id UUID NOT NULL, service_id UUID NOT NULL, PRIMARY KEY(doctor_id,service_id), FOREIGN KEY(clinic_id,doctor_id) REFERENCES doctor_profiles(clinic_id,id), FOREIGN KEY(clinic_id,service_id) REFERENCES services(clinic_id,id));
CREATE TABLE doctor_working_hours (id UUID PRIMARY KEY, clinic_id UUID NOT NULL, doctor_id UUID NOT NULL, day_of_week INT NOT NULL CHECK(day_of_week BETWEEN 1 AND 7), start_local_time TIME NOT NULL, end_local_time TIME NOT NULL CHECK(end_local_time > start_local_time), FOREIGN KEY(clinic_id,doctor_id) REFERENCES doctor_profiles(clinic_id,id));
CREATE INDEX hours_clinic ON doctor_working_hours(clinic_id,doctor_id);
CREATE TABLE doctor_schedule_exceptions (id UUID PRIMARY KEY, clinic_id UUID NOT NULL, doctor_id UUID NOT NULL, starts_at TIMESTAMPTZ NOT NULL, ends_at TIMESTAMPTZ NOT NULL CHECK(ends_at > starts_at), type VARCHAR(30) NOT NULL CHECK(type IN ('UNAVAILABLE','AVAILABLE_OVERRIDE')), reason TEXT, FOREIGN KEY(clinic_id,doctor_id) REFERENCES doctor_profiles(clinic_id,id));
CREATE INDEX exceptions_clinic ON doctor_schedule_exceptions(clinic_id,doctor_id,starts_at);
CREATE TABLE appointments (id UUID PRIMARY KEY, clinic_id UUID NOT NULL REFERENCES clinics(id), patient_id UUID NOT NULL, doctor_id UUID NOT NULL, service_id UUID NOT NULL, starts_at TIMESTAMPTZ NOT NULL, ends_at TIMESTAMPTZ NOT NULL CHECK(ends_at > starts_at), status VARCHAR(20) NOT NULL CHECK(status IN ('SCHEDULED','CONFIRMED','COMPLETED','CANCELLED','NO_SHOW')), service_name_snapshot VARCHAR(255) NOT NULL, duration_minutes_snapshot INT NOT NULL CHECK(duration_minutes_snapshot BETWEEN 5 AND 480), price_minor_snapshot BIGINT NOT NULL CHECK(price_minor_snapshot >= 0), currency_snapshot CHAR(3) NOT NULL, cancellation_reason TEXT, created_by_user_id UUID NOT NULL REFERENCES users(id), version INT NOT NULL DEFAULT 1, created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(), UNIQUE(clinic_id,id), FOREIGN KEY(clinic_id,patient_id) REFERENCES patients(clinic_id,id), FOREIGN KEY(clinic_id,doctor_id) REFERENCES doctor_profiles(clinic_id,id), FOREIGN KEY(clinic_id,service_id) REFERENCES services(clinic_id,id), CONSTRAINT appointments_no_doctor_overlap EXCLUDE USING gist (doctor_id WITH =, tstzrange(starts_at,ends_at,'[)') WITH &&) WHERE (status IN ('SCHEDULED','CONFIRMED')));
CREATE INDEX appointments_calendar ON appointments(clinic_id,starts_at);
CREATE INDEX appointments_doctor ON appointments(clinic_id,doctor_id,starts_at);
CREATE INDEX appointments_patient ON appointments(clinic_id,patient_id,starts_at DESC);
CREATE INDEX appointments_status ON appointments(clinic_id,status,starts_at);
CREATE TABLE appointment_events (id UUID PRIMARY KEY, clinic_id UUID NOT NULL, appointment_id UUID NOT NULL, actor_user_id UUID REFERENCES users(id), event_type VARCHAR(50) NOT NULL, old_value JSONB NOT NULL DEFAULT '{}', new_value JSONB NOT NULL DEFAULT '{}', occurred_at TIMESTAMPTZ NOT NULL DEFAULT now(), FOREIGN KEY(clinic_id,appointment_id) REFERENCES appointments(clinic_id,id));
CREATE INDEX events_clinic ON appointment_events(clinic_id,appointment_id,occurred_at);
CREATE TABLE payments (id UUID PRIMARY KEY, clinic_id UUID NOT NULL, appointment_id UUID NOT NULL, amount_minor BIGINT NOT NULL CHECK(amount_minor <> 0), currency CHAR(3) NOT NULL, method VARCHAR(30) NOT NULL CHECK(method IN ('CASH','CARD_TERMINAL','BANK_TRANSFER','OTHER')), type VARCHAR(20) NOT NULL CHECK(type IN ('PAYMENT','REVERSAL')), reverses_payment_id UUID UNIQUE REFERENCES payments(id), received_at TIMESTAMPTZ NOT NULL DEFAULT now(), created_by_user_id UUID NOT NULL REFERENCES users(id), comment TEXT, created_at TIMESTAMPTZ NOT NULL DEFAULT now(), FOREIGN KEY(clinic_id,appointment_id) REFERENCES appointments(clinic_id,id), CHECK((type='PAYMENT' AND amount_minor>0 AND reverses_payment_id IS NULL) OR (type='REVERSAL' AND amount_minor<0 AND reverses_payment_id IS NOT NULL)));
CREATE INDEX payments_clinic ON payments(clinic_id,appointment_id,received_at);
CREATE TABLE refresh_tokens (id UUID PRIMARY KEY, user_id UUID NOT NULL REFERENCES users(id), token_hash CHAR(64) NOT NULL UNIQUE, family_id UUID NOT NULL, expires_at TIMESTAMPTZ NOT NULL, revoked_at TIMESTAMPTZ, created_at TIMESTAMPTZ NOT NULL DEFAULT now(), replaced_by_token_id UUID REFERENCES refresh_tokens(id));
CREATE TABLE access_tokens (token_hash CHAR(64) PRIMARY KEY, user_id UUID NOT NULL REFERENCES users(id), family_id UUID NOT NULL, expires_at TIMESTAMPTZ NOT NULL);
CREATE TABLE password_reset_tokens (token_hash CHAR(64) PRIMARY KEY, user_id UUID NOT NULL REFERENCES users(id), expires_at TIMESTAMPTZ NOT NULL, used_at TIMESTAMPTZ);
CREATE TABLE rate_limits (key CHAR(64) PRIMARY KEY, hits INT NOT NULL DEFAULT 1, window_start TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE audit_logs (id UUID PRIMARY KEY, clinic_id UUID REFERENCES clinics(id), actor_user_id UUID REFERENCES users(id), action VARCHAR(100) NOT NULL, subject_type VARCHAR(50) NOT NULL, subject_id UUID, changes JSONB NOT NULL DEFAULT '{}', correlation_id VARCHAR(64) NOT NULL, occurred_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX audit_clinic ON audit_logs(clinic_id,occurred_at DESC);
CREATE TABLE idempotency_keys (id UUID PRIMARY KEY, clinic_id UUID NOT NULL REFERENCES clinics(id), actor_user_id UUID NOT NULL REFERENCES users(id), endpoint VARCHAR(100) NOT NULL, key VARCHAR(200) NOT NULL, request_hash CHAR(64) NOT NULL, response_code INT NOT NULL, response_body JSONB NOT NULL, expires_at TIMESTAMPTZ NOT NULL, UNIQUE(clinic_id,actor_user_id,endpoint,key));
CREATE TABLE outbox_messages (id UUID PRIMARY KEY, clinic_id UUID NOT NULL REFERENCES clinics(id), event_type VARCHAR(100) NOT NULL, payload JSONB NOT NULL, occurred_at TIMESTAMPTZ NOT NULL DEFAULT now(), published_at TIMESTAMPTZ, attempts INT NOT NULL DEFAULT 0);
CREATE TABLE notification_deliveries (id UUID PRIMARY KEY, clinic_id UUID NOT NULL, appointment_id UUID NOT NULL, recipient VARCHAR(255) NOT NULL, channel VARCHAR(20) NOT NULL DEFAULT 'EMAIL', template_key VARCHAR(100) NOT NULL, deduplication_key VARCHAR(255) NOT NULL UNIQUE, status VARCHAR(20) NOT NULL DEFAULT 'PENDING', attempts INT NOT NULL DEFAULT 0, last_error_code VARCHAR(100), appointment_version INT NOT NULL, sent_at TIMESTAMPTZ, created_at TIMESTAMPTZ NOT NULL DEFAULT now(), FOREIGN KEY(clinic_id,appointment_id) REFERENCES appointments(clinic_id,id));
SQL;
        foreach (explode(';', $sql) as $statement) {
            if ('' !== trim($statement)) {
                $this->addSql($statement);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Use a verified backup to restore medical operations data.');
    }
}
