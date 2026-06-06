export interface ApiEnvelope<T> {
  success: boolean;
  message: string;
  data: T;
}

export interface AuthUser {
  id: string;
  full_name: string;
  email: string;
  phone: string | null;
  avatar_url: string | null;
  role: "super_admin" | "user";
  is_verified: boolean;
  email_verified_at: string | null;
}

export interface AuthTokenResponse {
  access_token: string;
  token_type: string;
  expires_in: number;
  user: AuthUser;
}

export interface Plan {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  price_monthly: number;
  price_yearly: number;
  is_active: boolean;
  is_public: boolean;
  sort_order: number;
  features?: Record<string, string>;
}

export interface FeatureDefinition {
  id: string;
  feature_key: string;
  feature_label: string;
  feature_group: string | null;
  feature_type: "boolean" | "numeric" | "text";
  description: string | null;
  sort_order: number;
}

export interface Organization {
  id: string;
  name: string;
  slug: string;
  logo_url: string | null;
  description: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  custom_domain: string | null;
  owner_id: string;
  plan_id: string | null;
  plan_expires_at: string | null;
  plan?: Plan;
}

export interface Subscription {
  id: string;
  organization_id: string;
  plan_id: string | null;
  billing_cycle: "monthly" | "yearly";
  amount: number;
  status: "active" | "past_due" | "cancelled" | "expired";
  starts_at: string | null;
  expires_at: string | null;
  midtrans_order_id: string | null;
  paid_at: string | null;
  plan?: Plan;
}

export interface CheckoutResult {
  subscription: Subscription;
  snap_token: string | null;
  redirect_url: string | null;
  mock: boolean;
}
