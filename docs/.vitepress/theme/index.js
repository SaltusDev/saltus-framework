import DefaultTheme from 'vitepress/theme'
import VPNavBarTitle from './components/VPNavBarTitle.vue'

export default {
  extends: DefaultTheme,
  enhanceApp({ app }) {
    app.component('VPNavBarTitle', VPNavBarTitle)
  }
}
