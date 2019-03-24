new Vue({
  el: '#app',
  data: {
    query: '',
  },
  methods: {
    onSubmit(e) {
      // e.preventDefault(); // vanilla
    },
    onReset() {
      this.query = '';
      debugger
    },
    onKeyup() {
      if(!this.query.length) {
        this.onReset();
      }
    },
  }
});