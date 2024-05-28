<template>
  <span class="available-spots-component" :class="classes">
    {{ value }}
  </span>
</template>

<script>
export default {
  name: 'AvailableSpots',
  props: {
    spots: {
      type: Number,
      default: 0
    },
    big: {
      type: Boolean,
      default: false
    },
    waitList: {
      type: Number,
      default: 0
    }
  },
  computed: {
    value() {
      if (this.spots === 0) {
        if (this.waitList > 0) {
          return this.t('Waiting list')
        }
        return this.t('Full')
      } else if (this.spots < 10) {
        return this.formatPlural(this.spots, '1 spot', '@count spots')
      } else {
        return this.t('10+ spots')
      }
    },
    classes() {
      const classes = []
      if (this.spots === 0) {
        if (this.waitList > 0) {
          classes.push('wait-list')
        } else {
          classes.push('full')
        }
      } else if (this.spots <= 3) {
        classes.push('low')
      }

      if (this.big) {
        classes.push('big')
      }

      return classes
    }
  }
}
</script>

<style lang="scss">
.available-spots-component {
  display: inline;
  font-size: 16px;
  font-weight: 700;
  line-height: 18px;
  height: auto;
  padding: 6px 12px;
  color: $af-black;
  background-color: $white;
  border: 1px solid $af-dark-gray;
  border-radius: $af-border-radius;
  margin-left: auto;

  @include media-breakpoint-up('lg') {
    font-size: 16px;
  }

  &.big {
    font-size: 16px;
    line-height: 23px;
    height: auto;
  }

  &.full {
    color: $white;
    background-color: $af-black;
    border-color: $af-black;
    font-weight: bold;
  }

  &.low {
    color: $white;
    background-color: $af-red;
    border-color: $af-red;
    font-weight: bold;
  }

  &.wait-list {
    color: $white;
    background-color: $af-dark-gray;
    border-color: $af-dark-gray;
    font-weight: bold;
    text-transform: uppercase;
  }
}
</style>
