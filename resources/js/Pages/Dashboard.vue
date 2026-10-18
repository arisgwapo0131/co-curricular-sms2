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
            class="flex items-center space-x-3 px-3 py-2.5 rounded-lg bg-blue-600/20 text-blue-400 font-medium border border-blue-500/30 transition"
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
            class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-slate-200 font-medium transition"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
            <span>Achievements</span>
          </a>
        </nav>
      </div>

      <!-- User Profile Card in Sidebar -->
      <div class="space-y-3">
        <div class="p-3 bg-slate-950/60 rounded-xl border border-slate-800 flex items-center justify-between">
          <div class="flex items-center space-x-2.5 truncate">
            <div class="h-8 w-8 rounded-lg bg-blue-600/30 text-blue-400 font-bold flex items-center justify-center text-xs shrink-0 border border-blue-500/20">
              {{ (currentUser?.first_name || 'U').charAt(0) }}
            </div>
            <div class="truncate text-xs">
              <div class="font-bold text-white truncate">{{ currentUser?.first_name }} {{ currentUser?.last_name }}</div>
              <div class="text-[10px] text-slate-400 capitalize font-mono">{{ currentUser?.role || 'Guest' }}</div>
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

        <div class="p-3 bg-slate-950/60 rounded-xl border border-emerald-500/20 text-xs">
          <div class="flex items-center space-x-2 text-emerald-400 font-semibold mb-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            <span>Laravel Database Mode</span>
          </div>
          <p class="text-slate-400 text-[11px] leading-tight">Live MySQL Connection (sms_db)</p>
        </div>
      </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto p-8 space-y-8">
      <!-- Top Header -->
      <header class="flex justify-between items-center pb-6 border-b border-slate-800">
        <div>
          <h2 class="text-2xl font-extrabold text-white tracking-tight">Co-Curricular Management Overview</h2>
          <p class="text-slate-400 text-sm mt-1">Student Governance &amp; Campus Organizations Portal</p>
        </div>
        <div class="flex items-center space-x-3">
          <span class="px-3 py-1 rounded-full bg-emerald-500/10 text-emerald-400 text-xs font-semibold border border-emerald-500/20 flex items-center space-x-1.5">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
            <span>Live MySQL Database</span>
          </span>
          <span class="px-3 py-1 rounded-full bg-blue-500/10 text-blue-400 text-xs font-semibold border border-blue-500/20">
            Role: {{ currentUser?.role }}
          </span>
        </div>
      </header>

      <!-- Stat Cards: 100% Dynamically Fetched from Database -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <div class="bg-slate-900/70 border border-slate-800 rounded-2xl p-5 backdrop-blur-xl hover:border-blue-500/40 transition shadow-lg">
          <div class="text-slate-400 text-xs font-medium uppercase tracking-wider">Total Enrolled Students</div>
          <div class="text-3xl font-extrabold text-white mt-2">{{ metrics.total_students.toLocaleString() }}</div>
          <div class="text-blue-400 text-xs mt-2 font-medium flex items-center space-x-1">
            <span>Verified in database</span>
          </div>
        </div>

        <div class="bg-slate-900/70 border border-slate-800 rounded-2xl p-5 backdrop-blur-xl hover:border-blue-500/40 transition shadow-lg">
          <div class="text-slate-400 text-xs font-medium uppercase tracking-wider">Active Recognized Clubs</div>
          <div class="text-3xl font-extrabold text-white mt-2">{{ metrics.active_clubs.toLocaleString() }}</div>
          <div class="text-indigo-400 text-xs mt-2 font-medium flex items-center space-x-1">
            <span>Accredited organizations</span>
          </div>
        </div>

        <div class="bg-slate-900/70 border border-slate-800 rounded-2xl p-5 backdrop-blur-xl hover:border-blue-500/40 transition shadow-lg">
          <div class="text-slate-400 text-xs font-medium uppercase tracking-wider">Verified Achievements</div>
          <div class="text-3xl font-extrabold text-emerald-400 mt-2">{{ metrics.verified_achievements.toLocaleString() }}</div>
          <div class="text-emerald-400 text-xs mt-2 font-medium flex items-center space-x-1">
            <span>Council audited &amp; verified</span>
          </div>
        </div>

        <div class="bg-slate-900/70 border border-slate-800 rounded-2xl p-5 backdrop-blur-xl hover:border-blue-500/40 transition shadow-lg">
          <div class="text-slate-400 text-xs font-medium uppercase tracking-wider">Pending Budget Requests</div>
          <div class="text-3xl font-extrabold text-amber-400 mt-2">{{ metrics.pending_budgets.toLocaleString() }}</div>
          <div class="text-amber-400 text-xs mt-2 font-medium flex items-center space-x-1">
            <span>Multi-tier review workflow</span>
          </div>
        </div>
      </div>

      <!-- Two-Column Layout for Events & Announcements -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Recent Events Card -->
        <div class="bg-slate-900/70 border border-slate-800 rounded-2xl p-6 backdrop-blur-xl">
          <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-800">
            <h3 class="text-base font-bold text-white flex items-center space-x-2">
              <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
              <span>Recent &amp; Upcoming Events</span>
            </h3>
            <span class="text-xs text-slate-500">{{ recentEvents.length }} records</span>
          </div>

          <div v-if="recentEvents.length === 0" class="py-8 text-center text-slate-500 text-sm">
            No events scheduled currently.
          </div>
          <div v-else class="space-y-3">
            <div
              v-for="evt in recentEvents"
              :key="evt.id"
              class="p-3.5 rounded-xl bg-slate-950/60 border border-slate-800/80 hover:border-slate-700 transition"
            >
              <div class="flex items-start justify-between">
                <div>
                  <h4 class="font-semibold text-sm text-white">{{ evt.title }}</h4>
                  <p class="text-xs text-slate-400 mt-0.5 line-clamp-1">{{ evt.description }}</p>
                  <div class="flex items-center space-x-3 text-[11px] text-slate-400 mt-2">
                    <span v-if="evt.venue">📍 {{ evt.venue }}</span>
                    <span>📅 {{ evt.event_date ? new Date(evt.event_date).toLocaleDateString() : 'TBD' }}</span>
                  </div>
                </div>
                <span
                  class="px-2 py-0.5 rounded text-[10px] font-semibold uppercase"
                  :class="{
                    'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20': evt.status === 'Approved',
                    'bg-amber-500/10 text-amber-400 border border-amber-500/20': evt.status?.includes('Pending'),
                    'bg-slate-700/50 text-slate-300': !evt.status || evt.status === 'Draft'
                  }"
                >
                  {{ evt.status }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Campus Announcements Card -->
        <div class="bg-slate-900/70 border border-slate-800 rounded-2xl p-6 backdrop-blur-xl">
          <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-800">
            <h3 class="text-base font-bold text-white flex items-center space-x-2">
              <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
              <span>Announcements &amp; Bulletins</span>
            </h3>
            <span class="text-xs text-slate-500">{{ announcements.length }} records</span>
          </div>

          <div v-if="announcements.length === 0" class="py-8 text-center text-slate-500 text-sm">
            No campus announcements found.
          </div>
          <div v-else class="space-y-3">
            <div
              v-for="anc in announcements"
              :key="anc.id"
              class="p-3.5 rounded-xl bg-slate-950/60 border border-slate-800/80 hover:border-slate-700 transition"
            >
              <div class="flex items-start justify-between">
                <div>
                  <h4 class="font-semibold text-sm text-white">{{ anc.title }}</h4>
                  <p class="text-xs text-slate-400 mt-0.5 line-clamp-2">{{ anc.content }}</p>
                  <div class="flex items-center space-x-3 text-[11px] text-slate-500 mt-2">
                    <span>Category: {{ anc.category }}</span>
                    <span>Priority: {{ anc.priority }}</span>
                  </div>
                </div>
                <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-blue-500/10 text-blue-400 border border-blue-500/20">
                  {{ anc.scope }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Featured Accredited Clubs -->
      <div class="bg-slate-900/70 border border-slate-800 rounded-2xl p-6 backdrop-blur-xl">
        <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-800">
          <div>
            <h3 class="text-base font-bold text-white">Recognized Campus Student Organizations</h3>
            <p class="text-xs text-slate-400">Live roster of chartered student councils and academic societies</p>
          </div>
          <a href="/clubs" class="text-xs text-blue-400 hover:text-blue-300 font-medium">View all clubs &rarr;</a>
        </div>

        <div v-if="featuredClubs.length === 0" class="py-8 text-center text-slate-500 text-sm">
          No clubs currently recorded in database.
        </div>
        <div v-else class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div
            v-for="club in featuredClubs"
            :key="club.id"
            class="p-4 rounded-xl bg-slate-950/60 border border-slate-800 hover:border-blue-500/30 transition flex flex-col justify-between"
          >
            <div>
              <div class="flex items-center justify-between mb-2">
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-blue-500/20 text-blue-300 border border-blue-500/30">
                  {{ club.code }}
                </span>
                <span class="text-[11px] text-slate-400 font-medium">{{ club.category }}</span>
              </div>
              <h4 class="font-bold text-sm text-white line-clamp-1">{{ club.name }}</h4>
              <p class="text-xs text-slate-400 mt-1 line-clamp-2">{{ club.description }}</p>
            </div>
            <div class="mt-4 pt-3 border-t border-slate-800/80 flex items-center justify-between text-[11px]">
              <span class="text-slate-400">Adviser: <strong class="text-slate-200">{{ club.adviser_name || 'Assigned' }}</strong></span>
              <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">Active</span>
            </div>
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
  metrics: {
    type: Object,
    default: () => ({
      total_students: 0,
      active_clubs: 0,
      verified_achievements: 0,
      upcoming_events: 0,
      pending_budgets: 0,
    }),
  },
  recentEvents: {
    type: Array,
    default: () => [],
  },
  recentAchievements: {
    type: Array,
    default: () => [],
  },
  featuredClubs: {
    type: Array,
    default: () => [],
  },
  announcements: {
    type: Array,
    default: () => [],
  },
});

const page = usePage();
const currentUser = computed(() => page.props.auth?.user || null);

const logout = () => {
  router.post('/logout');
};
</script>
