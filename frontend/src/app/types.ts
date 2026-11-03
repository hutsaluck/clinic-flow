export type MembershipRole = "CLINIC_ADMIN" | "RECEPTIONIST" | "DOCTOR";
export interface SessionUser {
    id: string;
    email: string;
    first_name: string;
    last_name: string;
    platform_admin: boolean;
}
export interface Clinic {
    id: string;
    name: string;
    role: MembershipRole;
    timezone: string;
    currency: string;
    membership_id: string;
}
export interface SessionResponse {
    data: SessionUser;
    clinics: Clinic[];
}
