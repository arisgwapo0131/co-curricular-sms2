<template>
  <div class="min-h-screen bg-slate-950 flex flex-col justify-center py-12 sm:px-6 lg:px-8 text-slate-100 font-sans selection:bg-blue-500 selection:text-white relative overflow-hidden">
    <!-- Ambient background glow -->
    <div class="absolute top-1/4 left-1/2 -translate-x-1/2 -translate-y-1/2 w-96 h-96 bg-blue-600/15 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute bottom-10 right-10 w-80 h-80 bg-indigo-600/10 rounded-full blur-3xl pointer-events-none"></div>

    <div class="sm:mx-auto sm:w-full sm:max-w-md relative z-10">
      <div class="flex justify-center mb-4">
        <div class="h-14 w-14 rounded-2xl bg-gradient-to-tr from-blue-600 via-indigo-600 to-cyan-500 flex items-center justify-center font-black text-2xl text-white shadow-xl shadow-blue-500/25 border border-white/10">
          S
        </div>
      </div>
      <h2 class="text-center text-3xl font-extrabold tracking-tight text-white">
        Bestlink College of the Philippines
      </h2>
      <p class="mt-2 text-center text-sm text-slate-400">
        Co-Curricular Management System — <span class="text-blue-400 font-medium">Laravel &amp; Vue Stack</span>
      </p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md relative z-10">
      <div class="bg-slate-900/80 backdrop-blur-xl py-8 px-6 shadow-2xl rounded-2xl sm:px-10 border border-slate-800">
        <!-- Error Alert -->
        <div v-if="form.errors.username || form.errors.password" class="mb-5 p-3.5 rounded-xl bg-red-950/50 border border-red-800/60 text-red-300 text-xs flex items-start space-x-2">
          <svg class="w-4 h-4 text-red-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <div>
            <div v-if="form.errors.username">{{ form.errors.username }}</div>
            <div v-if="form.errors.password">{{ form.errors.password }}</div>
          </div>
        </div>

        <form class="space-y-5" @submit.prevent="submit">
          <div>
            <label for="username" class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-1.5">
              Username or Email
            </label>
            <input
              id="username"
              v-model="form.username"
              type="text"
              required
              autocomplete="username"
              placeholder="e.g. scc.admin or admin@bcp.edu.ph"
              class="w-full px-3.5 py-2.5 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm transition"
            />
          </div>

          <div>
            <div class="flex items-center justify-between mb-1.5">
              <label for="password" class="block text-xs font-semibold uppercase tracking-wider text-slate-300">
                Password
              </label>
            </div>
            <input
              id="password"
              v-model="form.password"
              type="password"
              required
              autocomplete="current-password"
              placeholder="••••••••••••"
              class="w-full px-3.5 py-2.5 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm transition"
            />
          </div>

          <div class="flex items-center justify-between">
            <label class="flex items-center space-x-2 text-xs text-slate-400 cursor-pointer">
              <input
                v-model="form.remember"
                type="checkbox"
                class="rounded bg-slate-950 border-slate-700 text-blue-600 focus:ring-blue-500 focus:ring-offset-slate-900"
              />
              <span>Remember me</span>
            </label>
            <span class="text-xs text-slate-500">Argon2id Hash Guard</span>
          </div>

          <div>
            <button
              type="submit"
              :disabled="form.processing"
              class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-xl shadow-lg shadow-blue-600/30 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 via-blue-700 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-slate-900 focus:ring-blue-500 disabled:opacity-50 transition cursor-pointer"
            >
              <span v-if="form.processing">Signing in...</span>
              <span v-else>Sign In</span>
            </button>
          </div>
        </form>

        <!-- Institutional Authentication Notice -->
        <div class="mt-6 pt-5 border-t border-slate-800 text-center">
          <p class="text-xs text-slate-400">
            Accounts are provisioned by the Office of Student Affairs &amp; Services.
          </p>
          <p class="text-[11px] text-slate-500 mt-1">
            Please enter your designated institutional username and password.
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';

const form = useForm({
  username: '',
  password: '',
  remember: false,
});

const submit = () => {
  form.post('/login', {
    onFinish: () => form.reset('password'),
  });
};
</script>
