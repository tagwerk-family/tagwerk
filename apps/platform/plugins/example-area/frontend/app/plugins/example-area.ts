import ExampleAreaView from '../components/ExampleAreaView.vue'

export default defineNuxtPlugin(() => {
  registerAreaView('fahoba/example-area', ExampleAreaView)
})
