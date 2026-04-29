import pkg from "../package.json";

export const APP_VERSION = pkg.version || "";
export const APP_BUILD_LABEL = "v3 sandbox";
export const APP_VERSION_DISPLAY = APP_VERSION
  ? `${APP_VERSION} (${APP_BUILD_LABEL})`
  : "";
