<template>
  <div class="results-bar-component">
    <div class="container">
      <div class="row">
        <div class="col-12 col-xs-12 col-sm-8 col-sm-offset-2 m-auto">
          <div class="controls">
            <span v-if="!disableSearchBox" v-b-modal.activity-finder-search class="control search">
              <a role="button">
                <Icon icon="material-symbols:search" width="1.2rem" height="1.2rem" />
                {{ 'Search' | t }}
              </a>
            </span>
            <span v-b-modal.activity-finder-filter class="control filter">
              <a role="button">
                <Icon icon="material-symbols:filter-list" width="1.2rem" height="1.2rem" />
                {{ 'Filter' | t }}
              </a>
            </span>
            <span v-b-modal.activity-finder-sort class="control sort">
              <a role="button">
                <Icon icon="material-symbols:swap-vert" width="1.2rem" height="1.2rem" />
                {{ 'Sort' | t }}
              </a>
            </span>
          </div>
        </div>
      </div>
      <Modal id="activity-finder-search" v-model="searchModal.visible" title="Search" flyout>
        <div class="results-bar-modal-content">
          <slot name="search" :hide-modal="hideSearchModal" />
        </div>
      </Modal>
      <Modal id="activity-finder-filter" v-model="filterModal.visible" title="Filter" flyout>
        <div class="results-bar-modal-content">
          <slot name="filter" :hide-modal="hideFilterModal" />
        </div>
      </Modal>
      <Modal id="activity-finder-sort" v-model="sortModal.visible" title="Sort" flyout>
        <div class="results-bar-modal-content">
          <slot name="sort" :hide-modal="hideSortModal" />
        </div>
      </Modal>
    </div>
  </div>
</template>

<script>
import Modal from '@/components/modals/Modal'
import { Icon } from '@iconify/vue2'

export default {
  name: 'ResultsBar',
  components: {
    Modal,
    Icon
  },
  props: {
    disableSearchBox: {
      type: Boolean,
      required: true
    }
  },
  data() {
    return {
      searchModal: {
        visible: false
      },
      filterModal: {
        visible: false
      },
      sortModal: {
        visible: false
      }
    }
  },
  methods: {
    hideSearchModal() {
      this.searchModal.visible = false
    },
    hideFilterModal() {
      this.filterModal.visible = false
    },
    hideSortModal() {
      this.sortModal.visible = false
    }
  }
}
</script>

<style lang="scss">
.results-bar-component {
  background-color: $af-light-gray;

  .controls {
    display: flex;
    justify-content: space-around;
    align-items: center;
    padding: 10px 0;
    font-size: 12px;
    line-height: 18px;
    font-weight: bold;

    .control {
      padding: 6px;

      a {
        color: $af-blue;

        svg {
          position: relative;
          top: -3px;
        }
      }
    }
  }
}

.results-bar-modal-content {
  padding: 20px 10px;
  height: 100%;
}
</style>
