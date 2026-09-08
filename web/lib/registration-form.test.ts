import { expect, test } from "bun:test";

import { keyFrom, uniqueKey } from "./registration-form";

/**
 * `bun test lib/registration-form.test.ts` — runner bawaan bun, tanpa dependensi
 * baru (pola `proxy.test.ts`).
 *
 * Yang diuji cuma penurunan key, karena kolomnya sudah tidak ada di layar:
 * organizer mengetik label dan **tidak bisa** memperbaiki key-nya sendiri, jadi
 * key yang salah tidak punya jalan keluar selain 422 di kasir.
 */

test("label jadi key yang sah", () => {
  expect(keyFrom("Alamat Tim")).toBe("alamat_tim");
  expect(keyFrom("No. KTP")).toBe("no_ktp");
  expect(keyFrom("   ")).toBe("");
});

test("baris tidak bertabrakan dengan dirinya sendiri", () => {
  // Mengetik ulang label yang sama tidak boleh menggeser key ke `alamat_2` —
  // rename ditolak server, dan jawaban yang sudah tersimpan ikut terlantar.
  const rows = [{ key: "alamat" }, { key: "alamat_2" }];
  expect(uniqueKey("Alamat", rows, 0)).toBe("alamat");
});

test("dua label sama dapat key berbeda, melewati yang sudah terpakai", () => {
  const rows = [{ key: "alamat" }, { key: "alamat_2" }, { key: "" }];
  expect(uniqueKey("Alamat", rows, 2)).toBe("alamat_3");
});
