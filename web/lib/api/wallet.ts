import { apiClient } from "./client";
import type {
  ApiEnvelope,
  BankAccount,
  Paginated,
  Wallet,
  WalletTransaction,
  Withdrawal,
} from "@/types/api";

export interface BankAccountInput {
  bank_name: string;
  bank_code?: string | null;
  account_number: string;
  account_holder: string;
}

export interface WithdrawalInput {
  amount: number;
  note?: string | null;
}

// ---- Organizer (tenant-scoped; owner/admin only) ----

export async function getWallet(orgId: string): Promise<Wallet> {
  const { data } = await apiClient.get<ApiEnvelope<Wallet>>(`/organizations/${orgId}/wallet`);
  return data.data;
}

export async function getWalletTransactions(
  orgId: string,
  page = 1
): Promise<Paginated<WalletTransaction>> {
  const { data } = await apiClient.get<ApiEnvelope<Paginated<WalletTransaction>>>(
    `/organizations/${orgId}/wallet/transactions`,
    { params: { page } }
  );
  return data.data;
}

export async function getBankAccounts(orgId: string): Promise<BankAccount[]> {
  const { data } = await apiClient.get<ApiEnvelope<BankAccount[]>>(
    `/organizations/${orgId}/bank-accounts`
  );
  return data.data;
}

export async function createBankAccount(
  orgId: string,
  payload: BankAccountInput
): Promise<BankAccount> {
  const { data } = await apiClient.post<ApiEnvelope<BankAccount>>(
    `/organizations/${orgId}/bank-accounts`,
    payload
  );
  return data.data;
}

export async function getWithdrawals(orgId: string): Promise<Withdrawal[]> {
  const { data } = await apiClient.get<ApiEnvelope<Withdrawal[]>>(
    `/organizations/${orgId}/withdrawals`
  );
  return data.data;
}

export async function createWithdrawal(
  orgId: string,
  payload: WithdrawalInput
): Promise<Withdrawal> {
  const { data } = await apiClient.post<ApiEnvelope<Withdrawal>>(
    `/organizations/${orgId}/withdrawals`,
    payload
  );
  return data.data;
}

export async function cancelWithdrawal(orgId: string, id: string): Promise<void> {
  await apiClient.delete(`/organizations/${orgId}/withdrawals/${id}`);
}
