import { expect, test } from "bun:test";

import { keyFrom, missingFor, uniqueKey, type CustomField } from "./registration-form";

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

const field = (over: Partial<CustomField> & Pick<CustomField, "key" | "label">): CustomField => ({
  type: "short_text",
  required: true,
  is_public: false,
  in_album: false,
  options: [],
  ...over,
});

/**
 * Label consent adalah kalimat utuh, karena kalimat itulah yang disetujui.
 * Dibaca satu per satu di baris "Belum lengkap", ia jadi paragraf — padahal
 * gunanya justru supaya orang bisa memindai apa yang masih kurang.
 *
 * Dibandingkan dengan field biasa di payload yang sama: assert "ada pernyataan
 * persetujuan" sendirian akan lolos walau nama field ikut tertelan jadi
 * hitungan, dan orang kehilangan satu-satunya petunjuk field mana.
 */
test("consent dihitung, field biasa tetap disebut namanya", () => {
  const missing = missingFor(
    [
      field({ key: "nama_bib", label: "Nama BIB" }),
      field({ key: "sehat", label: "Saya menyatakan dalam kondisi sehat…", type: "consent" }),
      field({ key: "risiko", label: "Saya memahami risiko lomba…", type: "consent" }),
    ],
    [],
    {},
    [],
  );

  expect(missing).toEqual(["Nama BIB", "2 pernyataan persetujuan"]);
});

test("satu consent dihitung tunggal", () => {
  const missing = missingFor([field({ key: "sehat", label: "Saya…", type: "consent" })], [], {}, []);

  expect(missing).toEqual(["1 pernyataan persetujuan"]);
});

/**
 * Yang sudah dicentang tidak boleh ikut terhitung — kalau ikut, baris itu
 * menagih sesuatu yang sudah dikerjakan dan tombolnya tidak pernah menyala.
 */
test("consent yang sudah dicentang tidak dihitung", () => {
  const fields = [
    field({ key: "sehat", label: "Saya…", type: "consent" }),
    field({ key: "risiko", label: "Saya…", type: "consent" }),
  ];

  expect(missingFor(fields, [], { sehat: "2026-10-18T00:00:00Z" }, [])).toEqual([
    "1 pernyataan persetujuan",
  ]);
  expect(
    missingFor(fields, [], { sehat: "2026-10-18T00:00:00Z", risiko: "2026-10-18T00:00:00Z" }, []),
  ).toEqual([]);
});
