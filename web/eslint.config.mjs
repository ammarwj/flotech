import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  {
    rules: {
      // Native dialogs can't be themed and force title and body into one
      // string. Use useConfirm() / usePrompt() from
      // components/shared/confirm-provider instead.
      "no-restricted-globals": [
        "error",
        {
          name: "confirm",
          message: "Pakai useConfirm() dari @/components/shared/confirm-provider.",
        },
        {
          name: "prompt",
          message: "Pakai usePrompt() dari @/components/shared/confirm-provider.",
        },
        { name: "alert", message: "Pakai toast dari sonner." },
      ],
    },
  },
  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
  ]),
]);

export default eslintConfig;
