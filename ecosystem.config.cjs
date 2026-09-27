module.exports = {
  apps: [
    {
      name: process.env.PM2_APP_NAME || "ismile",
      script: "server/node-server.mjs",
      cwd: __dirname,
      instances: 1,
      exec_mode: "fork",
      env: {
        NODE_ENV: "production",
        PORT: process.env.APP_PORT || process.env.PORT || 3000,
      },
    },
  ],
};
