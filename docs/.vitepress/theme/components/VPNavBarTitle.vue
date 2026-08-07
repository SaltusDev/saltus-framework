<script setup lang="ts">
import { computed } from 'vue'
import { useData } from 'vitepress'
import { useSidebar } from 'vitepress/theme'

const { site, theme } = useData()
const { hasSidebar } = useSidebar()

const link = computed(() =>
  typeof theme.value.logoLink === 'string'
    ? theme.value.logoLink
    : theme.value.logoLink?.link
)

const rel = computed(() =>
  typeof theme.value.logoLink === 'string'
    ? undefined
    : theme.value.logoLink?.rel
)

const target = computed(() =>
  typeof theme.value.logoLink === 'string'
    ? undefined
    : theme.value.logoLink?.target
)

const logoSrc = computed(() => {
  const logo = theme.value.logo
  return typeof logo === 'string' ? logo : logo?.src
})

const logoAlt = computed(() => {
  const logo = theme.value.logo
  return typeof logo === 'object' ? logo?.alt : ''
})
</script>

<template>
  <div class="VPNavBarTitle" :class="{ 'has-sidebar': hasSidebar }">
    <a
      class="title"
      :href="link ?? '/'"
      :rel="rel"
      :target="target"
    >
      <img v-if="logoSrc" class="logo" :src="logoSrc" :alt="logoAlt">
      <span v-if="theme.siteTitle" v-html="theme.siteTitle"></span>
      <span v-else-if="theme.siteTitle === undefined">{{ site.title }}</span>
    </a>
    <a class="sub-link" href="https://docs.saltus.dev">Documentation</a>
  </div>
</template>

<style scoped>
.VPNavBarTitle {
  display: flex;
  flex-direction: column;
  justify-content: center;
  line-height: 1;
}

.title {
  display: flex;
  align-items: center;
  border-bottom: 1px solid transparent;
  width: 100%;
  height: calc(var(--vp-nav-height) * 0.6);
  font-size: 16px;
  font-weight: 600;
  color: var(--vp-c-text-1);
  transition: opacity 0.25s;
  text-decoration: none;
}

@media (min-width: 960px) {
  .VPNavBarTitle.has-sidebar .title {
    border-bottom: none;
  }
}

@media (max-width: 959px) {
  .sub-link {
    display: none;
  }
}

:deep(.logo) {
  margin-right: 8px;
  height: var(--vp-nav-logo-height);
}

.sub-link {
  font-size: 11px;
  font-weight: 500;
  color: var(--vp-c-text-2);
  text-decoration: none;
  line-height: 1;
  letter-spacing: 0.3px;
  text-transform: uppercase;
  transition: color 0.2s;
  margin-top: 1px;
}

.sub-link:hover {
  color: var(--vp-c-brand-1);
}
</style>
