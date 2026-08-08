/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  // Portal J-ERP: app di-serve di balik jerp-nginx pada path /legal.
  basePath: "/legal",
  experimental: {
    serverActions: { bodySizeLimit: "15mb" },
  },
};

export default nextConfig;
