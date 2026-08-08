/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  // Portal J-ERP: app di-serve di balik jerp-nginx pada path /sales.
  basePath: "/sales",
  serverExternalPackages: ["@resvg/resvg-js"],
};

export default nextConfig;
