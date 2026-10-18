<template>
  <div class="min-h-screen bg-slate-950 text-slate-100 flex font-sans selection:bg-blue-500 selection:text-white">
    <!-- Sidebar -->
    <aside class="w-64 bg-slate-900/90 backdrop-blur-xl border-r border-slate-800 flex flex-col justify-between p-4 shrink-0">
      <div>
        <div class="flex items-center space-x-3 px-2 py-4 mb-6 border-b border-slate-800">
          <div class="h-10 w-10 rounded-xl bg-gradient-to-tr from-blue-600 to-indigo-500 flex items-center justify-center font-bold text-xl shadow-lg shadow-blue-500/30 text-white">
            S
          </div>
          <div>
            <h1 class="font-bold text-lg leading-none tracking-tight text-white">BCP SMS</h1>
            <span class="text-xs text-blue-400 font-medium">Laravel &amp; Vue 3 Stack</span>
          </div>
        </div>

        <nav class="space-y-1">
          <a
            href="/dashboard"
            class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-slate-200 font-medium transition"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 00-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            <span>Dashboard</span>
          </a>
          <a
            href="/students"
            class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-slate-200 font-medium transition"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            <span>Student Roster</span>
          </a>
          <a
            href="/clubs"
            class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-slate-200 font-medium transition"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
            <span>Clubs &amp; Orgs</span>
          </a>
          <a
            href="/achievements"
            class="flex items-center space-x-3 px-3 py-2.5 rounded-lg bg-blue-600/20 text-blue-400 font-medium border border-blue-500/30 transition"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
            <span>Achievements</span>
          </a>
        </nav>
      </div>

      <div class="space-y-3">
        <div class="p-3 bg-slate-950/60 rounded-xl border border-slate-800 flex items-center justify-between">
          <div class="flex items-center space-x-2.5 truncate">
            <div class="h-8 w-8 rounded-lg bg-blue-600/30 text-blue-400 font-bold flex items-center justify-center text-xs shrink-0 border border-blue-500/20">
              {{ (currentUser?.first_name || 'U').charAt(0) }}
            </div>
            <div class="truncate text-xs">
              <div class="font-bold text-white truncate">{{ currentUser?.first_name }} {{ currentUser?.last_name }}</div>
              <div class="text-[10px] text-slate-400 capitalize font-mono">{{ currentUser?.role }}</div>
            </div>
          </div>
          <button
            type="button"
            @click="logout"
            title="Sign Out"
            class="text-slate-400 hover:text-red-400 p-1.5 rounded-lg hover:bg-slate-800 transition shrink-0"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
          </button>
        </div>
      </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto p-8 space-y-6">
      <header class="pb-6 border-b border-slate-800">
        <h2 class="text-2xl font-extrabold text-white tracking-tight">Student &amp; Organization Achievements</h2>
        <p class="text-slate-400 text-sm mt-1">Verified competitions, honors, and co-curricular awards</p>
      </header>

      <div class="bg-slate-900/70 border border-slate-800 rounded-2xl overflow-hidden backdrop-blur-xl shadow-xl">
        <table class="w-full text-left text-sm text-slate-300">
          <thead class="bg-slate-950/80 text-[11px] uppercase tracking-wider text-slate-400 border-b border-slate-800">
            <tr>
              <th class="py-3.5 px-4 font-semibold">Award / Honor Title</th>
              <th class="py-3.5 px-4 font-semibold">Competition / Event</th>
              <th class="py-3.5 px-4 font-semibold">Award Date</th>
              <th class="py-3.5 px-4 font-semibold">Audit Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800/60">
            <tr v-if="achievements.data.length === 0">
              <td colspan="4" class="py-10 text-center text-slate-500">
                No achievements currently recorded.
              </td>
            </tr>
            <tr
              v-for="ach in achievements.data"
              :key="ach.id"
              class="hover:bg-slate-800/40 transition"
            >
              <td class="py-3.5 px-4 font-semibold text-white">{{ ach.title }}</td>
              <td class="py-3.5 px-4 text-slate-400 text-xs">{{ ach.competition }}</td>
              <td class="py-3.5 px-4 text-xs font-mono">{{ ach.award_date }}</td>
              <td class="py-3.5 px-4">
                <span
                  class="px-2 py-0.5 rounded text-[10px] font-semibold uppercase"
                  :class="{
                    'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20': ach.status === 'Verified',
                    'bg-amber-500/10 text-amber-400 border border-amber-500/20': ach.status === 'Pending',
                    'bg-red-500/10 text-red-400 border border-red-500/20': ach.status === 'Rejected',
                  }"
                >
                  {{ ach.status }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- Pagination Footer -->
        <div v-if="achievements.total > 0" class="p-4 bg-slate-950/60 border-t border-slate-800 flex items-center justify-end text-xs text-slate-400">
          <div class="flex space-x-1">
            <a
              v-for="link in achievements.links"
              :key="link.label"
              :href="link.url || '#'"
              class="px-3 py-1 rounded-lg border text-xs font-medium transition"
              :class="{
                'bg-blue-600 text-white border-blue-500': link.active,
                'bg-slate-900 border-slate-800 text-slate-400 hover:bg-slate-800': !link.active && link.url,
                'opacity-40 cursor-not-allowed border-slate-800': !link.url
              }"
              v-html="link.label"
            />
          </div>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { usePage, router } from '@inertiajs/vue3';

const props = defineProps({
  achievements: {
    type: Object,
    required: true,
  },
});

const page = usePage();
const currentUser = computed(() => page.props.auth?.user || null);

const logout = () => {
  router.post('/logout');
};
</script>
