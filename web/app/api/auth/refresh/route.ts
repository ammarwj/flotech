import { NextRequest, NextResponse } from "next/server";

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1";

/**
 * Forwards the browser's HttpOnly refresh cookie to Laravel's refresh endpoint,
 * relays any rotated Set-Cookie back to the browser, and returns the new
 * access token to the client (which keeps it in memory only).
 */
export async function POST(request: NextRequest) {
  const cookie = request.headers.get("cookie") ?? "";

  const upstream = await fetch(`${API_URL}/auth/refresh`, {
    method: "POST",
    headers: {
      cookie,
      Accept: "application/json",
    },
  });

  if (!upstream.ok) {
    return NextResponse.json({ error: "refresh_failed" }, { status: 401 });
  }

  const data = (await upstream.json()) as {
    data?: { access_token?: string };
    access_token?: string;
  };

  const accessToken = data.data?.access_token ?? data.access_token ?? null;

  const res = NextResponse.json({ accessToken });

  // Relay rotated refresh cookie (if any) to the browser.
  const setCookie = upstream.headers.get("set-cookie");
  if (setCookie) {
    res.headers.set("set-cookie", setCookie);
  }

  return res;
}
