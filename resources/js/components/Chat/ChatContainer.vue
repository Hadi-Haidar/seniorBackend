<template>
  <div class="chat-app">
    <!-- Sidebar with chat rooms -->
    <div class="chat-sidebar">
      <div class="sidebar-header">
        <h2>Chat Rooms</h2>
        <button @click="showNewRoomModal = true" class="new-room-btn">
          <i class="fas fa-plus"></i>
        </button>
      </div>

      <div class="rooms-list">
        <div
          v-for="room in rooms"
          :key="room.id"
          :class="['room-item', { active: currentRoom?.id === room.id }]"
          @click="selectRoom(room)"
        >
          <h3>{{ room.name }}</h3>
          <p class="room-description">{{ room.description }}</p>
          <div class="room-participants">
            {{ room.participants.length }} participants
          </div>
        </div>
      </div>
    </div>

    <!-- Main chat area -->
    <div class="chat-main">
      <ChatRoom
        v-if="currentRoom"
        :room-id="currentRoom.id"
        :current-user-id="currentUserId"
      />
      <div v-else class="no-room-selected">
        <p>Select a chat room or create a new one to start messaging</p>
      </div>
    </div>

    <!-- New Room Modal -->
    <div v-if="showNewRoomModal" class="modal">
      <div class="modal-content">
        <h2>Create New Chat Room</h2>
        <form @submit.prevent="createRoom">
          <div class="form-group">
            <label>Room Name</label>
            <input v-model="newRoom.name" type="text" required>
          </div>
          
          <div class="form-group">
            <label>Description</label>
            <textarea v-model="newRoom.description"></textarea>
          </div>

          <div class="form-group">
            <label>Add Participants</label>
            <select v-model="newRoom.participants" multiple>
              <option v-for="user in users" :key="user.id" :value="user.id">
                {{ user.name }}
              </option>
            </select>
          </div>

          <div class="modal-actions">
            <button type="button" @click="showNewRoomModal = false">Cancel</button>
            <button type="submit">Create Room</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, onMounted } from 'vue';
import ChatRoom from './ChatRoom.vue';

export default {
  name: 'ChatContainer',
  
  components: {
    ChatRoom
  },

  props: {
    currentUserId: {
      type: [Number, String],
      required: true
    }
  },

  setup() {
    const rooms = ref([]);
    const currentRoom = ref(null);
    const showNewRoomModal = ref(false);
    const users = ref([]);
    const newRoom = ref({
      name: '',
      description: '',
      participants: []
    });

    // Fetch all chat rooms
    const fetchRooms = async () => {
      try {
        const response = await axios.get('/api/rooms');
        rooms.value = response.data;
      } catch (error) {
        console.error('Error fetching rooms:', error);
      }
    };

    // Fetch all users for participant selection
    const fetchUsers = async () => {
      try {
        const response = await axios.get('/api/users');
        users.value = response.data;
      } catch (error) {
        console.error('Error fetching users:', error);
      }
    };

    // Select a room
    const selectRoom = (room) => {
      currentRoom.value = room;
    };

    // Create a new room
    const createRoom = async () => {
      try {
        const response = await axios.post('/api/rooms', newRoom.value);
        rooms.value.push(response.data);
        showNewRoomModal.value = false;
        newRoom.value = {
          name: '',
          description: '',
          participants: []
        };
      } catch (error) {
        console.error('Error creating room:', error);
      }
    };

    onMounted(() => {
      fetchRooms();
      fetchUsers();
    });

    return {
      rooms,
      currentRoom,
      showNewRoomModal,
      users,
      newRoom,
      selectRoom,
      createRoom
    };
  }
};
</script>

<style scoped>
.chat-app {
  display: flex;
  height: 100vh;
  background: #f9fafb;
}

.chat-sidebar {
  width: 300px;
  border-right: 1px solid #e5e7eb;
  background: white;
  display: flex;
  flex-direction: column;
}

.sidebar-header {
  padding: 1rem;
  border-bottom: 1px solid #e5e7eb;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.new-room-btn {
  padding: 0.5rem;
  background: #2563eb;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.rooms-list {
  flex: 1;
  overflow-y: auto;
  padding: 1rem;
}

.room-item {
  padding: 1rem;
  border-radius: 8px;
  cursor: pointer;
  margin-bottom: 0.5rem;
}

.room-item:hover {
  background: #f3f4f6;
}

.room-item.active {
  background: #e3f2fd;
}

.room-item h3 {
  margin: 0;
  font-size: 1rem;
}

.room-description {
  margin: 0.25rem 0;
  font-size: 0.875rem;
  color: #6b7280;
}

.room-participants {
  font-size: 0.75rem;
  color: #4b5563;
}

.chat-main {
  flex: 1;
  display: flex;
  flex-direction: column;
}

.no-room-selected {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #6b7280;
}

.modal {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
}

.modal-content {
  background: white;
  padding: 2rem;
  border-radius: 8px;
  width: 100%;
  max-width: 500px;
}

.form-group {
  margin-bottom: 1rem;
}

.form-group label {
  display: block;
  margin-bottom: 0.5rem;
  font-weight: 500;
}

.form-group input,
.form-group textarea,
.form-group select {
  width: 100%;
  padding: 0.5rem;
  border: 1px solid #e5e7eb;
  border-radius: 4px;
}

.form-group select {
  height: 100px;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 1rem;
  margin-top: 1rem;
}

.modal-actions button {
  padding: 0.5rem 1rem;
  border-radius: 4px;
  cursor: pointer;
}

.modal-actions button[type="button"] {
  background: #f3f4f6;
  border: 1px solid #e5e7eb;
}

.modal-actions button[type="submit"] {
  background: #2563eb;
  color: white;
  border: none;
}
</style> 