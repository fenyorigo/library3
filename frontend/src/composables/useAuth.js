import { reactive, ref } from "vue";
import { login, logout, me, setCsrfToken } from "../api";

export function useAuth(options = {}) {
  const user = ref(null);
  const showLoginModal = ref(false);
  const loginForm = reactive({
    username: "",
    password: "",
  });
  const loginLoading = ref(false);
  const loginError = ref("");

  const handleUnauthorized = () => {
    setCsrfToken('');
    user.value = null;
    showLoginModal.value = true;
    loginError.value = "";
    loginLoading.value = false;
    loginForm.password = "";
    if (typeof options.onUnauthorized === "function") {
      options.onUnauthorized();
    }
  };

  const fetchCurrentUser = async () => {
    try {
      const profile = await me();
      const payload = profile.data || {};
      if (payload.csrf_token) setCsrfToken(payload.csrf_token);
      user.value = payload.user ?? profile;
      showLoginModal.value = false;
      if (typeof options.onAuthenticated === "function") {
        options.onAuthenticated(user.value);
      }
      return true;
    } catch (err) {
      if (err && err.status === 401) {
        handleUnauthorized();
      } else {
        console.error("Fetching session failed", err);
      }
      return false;
    }
  };

  const initAuth = async () => {
    return fetchCurrentUser();
  };

  const openLoginPrompt = () => {
    loginError.value = "";
    loginForm.password = "";
    showLoginModal.value = true;
  };

  const onSubmitLogin = async () => {
    if (loginLoading.value) return false;
    const u = (loginForm.username || "").trim();
    const p = loginForm.password || "";
    if (!u || !p) {
      loginError.value = "Username and password are required.";
      return false;
    }
    loginLoading.value = true;
    loginError.value = "";
    try {
      const loginRes = await login(u, p);
      const adminToolsWarning = loginRes?.data?.admin_tools_warning;
      if (adminToolsWarning) {
        alert(adminToolsWarning);
      }
      loginForm.username = u;
      loginForm.password = "";
      const ok = await fetchCurrentUser();
      if (ok) {
        showLoginModal.value = false;
      }
      return ok;
    } catch (err) {
      loginError.value = err && err.message ? err.message : "Login failed.";
      return false;
    } finally {
      loginLoading.value = false;
    }
  };

  const onLogout = async () => {
    try {
      await logout();
    } catch (err) {
      console.error("Logout failed", err);
    } finally {
      handleUnauthorized();
    }
  };

  return {
    user,
    showLoginModal,
    loginForm,
    loginLoading,
    loginError,
    initAuth,
    fetchCurrentUser,
    handleUnauthorized,
    openLoginPrompt,
    onSubmitLogin,
    onLogout,
  };
}
