<template>
  <div class="chat-container">
    <!-- Chat Header -->
    <div class="chat-header">
      <h2>{{ room.name }}</h2>
      <div class="participants">
        <span v-for="participant in room.participants" :key="participant.id">
          {{ participant.name }}
        </span>
      </div>
    </div>

    <!-- Messages List -->
    <div class="messages-container" ref="messagesContainer">
      <div v-for="message in messages" :key="message.id" 
           :class="['message', { 'message-own': message.user_id === currentUserId }]">
        <div class="message-header">
          <span class="user-name">{{ message.user.name }}</span>
          <span class="timestamp">{{ formatDate(message.created_at) }}</span>
        </div>
        
        <!-- Text Message -->
        <div v-if="message.type === 'text'" class="message-content">
          {{ message.message }}
        </div>

        <!-- Image Message -->
        <div v-else-if="message.type === 'image'" class="message-content">
          <img :src="message.file_url" alt="Image" @click="openImage(message.file_url)">
          <p v-if="message.message">{{ message.message }}</p>
        </div>

        <!-- File Message -->
        <div v-else-if="message.type === 'file'" class="message-content">
          <a :href="message.file_url" target="_blank" class="file-download">
            <i class="fas fa-file"></i>
            Download File
          </a>
          <p v-if="message.message">{{ message.message }}</p>
        </div>

        <!-- Voice Message -->
        <div v-else-if="message.type === 'voice'" class="message-content">
          <audio controls>
            <source :src="message.file_url" type="audio/mpeg">
          </audio>
        </div>
      </div>
    </div>

    <!-- Message Input -->
    <div class="message-input">
      <div class="input-actions">
        <button @click="toggleEmojiPicker" class="action-btn">
          <i class="far fa-smile"></i>
        </button>
        <button @click="$refs.fileInput.click()" class="action-btn">
          <i class="fas fa-paperclip"></i>
        </button>
        <button @click="startVoiceRecording" v-if="!isRecording" class="action-btn">
          <i class="fas fa-microphone"></i>
        </button>
        <button @click="stopVoiceRecording" v-else class="action-btn recording">
          <i class="fas fa-stop"></i>
        </button>
      </div>

      <input type="file" ref="fileInput" @change="handleFileUpload" style="display: none">
      
      <textarea 
        v-model="newMessage" 
        @keyup.enter.exact="sendMessage"
        placeholder="Type a message..."
        rows="1"
        @input="autoResize"
      ></textarea>

      <button @click="sendMessage" class="send-btn">
        <i class="fas fa-paper-plane"></i>
      </button>
    </div>
  </div>
</template>

<script>
import { ref, onMounted, onUnmounted } from 'vue';
import Echo from 'laravel-echo';
import { formatDistanceToNow } from 'date-fns';

export default {
  name: 'ChatRoom',
  
  props: {
    roomId: {
      type: [Number, String],
      required: true
    },
    currentUserId: {
      type: [Number, String],
      required: true
    }
  },

  setup(props) {
    const room = ref({});
    const messages = ref([]);
    const newMessage = ref('');
    const messagesContainer = ref(null);
    const isRecording = ref(false);
    const mediaRecorder = ref(null);
    const audioChunks = ref([]);

    // Fetch initial data
    const fetchRoom = async () => {
      try {
        const response = await axios.get(`/api/rooms/${props.roomId}`);
        room.value = response.data;
        messages.value = response.data.messages;
        scrollToBottom();
      } catch (error) {
        console.error('Error fetching room:', error);
      }
    };

    // Scroll to bottom of messages
    const scrollToBottom = () => {
      if (messagesContainer.value) {
        setTimeout(() => {
          messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
        }, 100);
      }
    };

    // Send message
    const sendMessage = async () => {
      if (!newMessage.value.trim() && !selectedFile.value) return;

      const formData = new FormData();
      if (newMessage.value.trim()) {
        formData.append('message', newMessage.value);
        formData.append('type', 'text');
      }
      
      if (selectedFile.value) {
        formData.append('file', selectedFile.value);
        formData.append('type', getFileType(selectedFile.value));
      }

      try {
        await axios.post(`/api/chat-rooms/${props.roomId}/messages`, formData, {
          headers: {
            'Content-Type': 'multipart/form-data'
          }
        });
        
        newMessage.value = '';
        selectedFile.value = null;
      } catch (error) {
        console.error('Error sending message:', error);
      }
    };

    // File handling
    const selectedFile = ref(null);
    
    const handleFileUpload = (event) => {
      selectedFile.value = event.target.files[0];
      if (selectedFile.value) {
        sendMessage();
      }
    };

    const getFileType = (file) => {
      if (file.type.startsWith('image/')) return 'image';
      if (file.type.startsWith('audio/')) return 'voice';
      return 'file';
    };

    // Voice recording
    const startVoiceRecording = async () => {
      try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder.value = new MediaRecorder(stream);
        audioChunks.value = [];

        mediaRecorder.value.ondataavailable = (event) => {
          audioChunks.value.push(event.data);
        };

        mediaRecorder.value.onstop = () => {
          const audioBlob = new Blob(audioChunks.value, { type: 'audio/mpeg' });
          selectedFile.value = new File([audioBlob], 'voice-message.mp3', { type: 'audio/mpeg' });
          sendMessage();
        };

        mediaRecorder.value.start();
        isRecording.value = true;
      } catch (error) {
        console.error('Error starting recording:', error);
      }
    };

    const stopVoiceRecording = () => {
      if (mediaRecorder.value && isRecording.value) {
        mediaRecorder.value.stop();
        isRecording.value = false;
      }
    };

    // Date formatting
    const formatDate = (date) => {
      return formatDistanceToNow(new Date(date), { addSuffix: true });
    };

    // Textarea auto-resize
    const autoResize = (event) => {
      const textarea = event.target;
      textarea.style.height = 'auto';
      textarea.style.height = textarea.scrollHeight + 'px';
    };

    // Real-time updates
    onMounted(() => {
      fetchRoom();

      Echo.join(`chat.room.${props.roomId}`)
        .listen('.message.new', (e) => {
          messages.value.push(e.message);
          scrollToBottom();
        });
    });

    onUnmounted(() => {
      Echo.leave(`chat.room.${props.roomId}`);
    });

    return {
      room,
      messages,
      newMessage,
      messagesContainer,
      isRecording,
      sendMessage,
      handleFileUpload,
      startVoiceRecording,
      stopVoiceRecording,
      formatDate,
      autoResize
    };
  }
};
</script>

<style scoped>
.chat-container {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.chat-header {
  padding: 1rem;
  border-bottom: 1px solid #e5e7eb;
}

.chat-header h2 {
  margin: 0;
  font-size: 1.25rem;
}

.participants {
  margin-top: 0.5rem;
  font-size: 0.875rem;
  color: #6b7280;
}

.participants span:not(:last-child)::after {
  content: ", ";
}

.messages-container {
  flex: 1;
  overflow-y: auto;
  padding: 1rem;
}

.message {
  margin-bottom: 1rem;
  max-width: 70%;
}

.message-own {
  margin-left: auto;
  background: #e3f2fd;
  border-radius: 12px 12px 0 12px;
}

.message:not(.message-own) {
  background: #f3f4f6;
  border-radius: 12px 12px 12px 0;
}

.message-header {
  padding: 0.25rem 0.5rem;
  font-size: 0.875rem;
}

.user-name {
  font-weight: 600;
}

.timestamp {
  margin-left: 0.5rem;
  color: #6b7280;
}

.message-content {
  padding: 0.5rem;
}

.message-content img {
  max-width: 100%;
  border-radius: 4px;
  cursor: pointer;
}

.file-download {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem;
  background: #f3f4f6;
  border-radius: 4px;
  text-decoration: none;
  color: #1e40af;
}

.message-input {
  padding: 1rem;
  border-top: 1px solid #e5e7eb;
  display: flex;
  align-items: flex-end;
  gap: 0.5rem;
}

.input-actions {
  display: flex;
  gap: 0.5rem;
}

.action-btn {
  padding: 0.5rem;
  background: none;
  border: none;
  cursor: pointer;
  color: #6b7280;
  border-radius: 4px;
}

.action-btn:hover {
  background: #f3f4f6;
}

.action-btn.recording {
  color: #ef4444;
  animation: pulse 1s infinite;
}

textarea {
  flex: 1;
  padding: 0.5rem;
  border: 1px solid #e5e7eb;
  border-radius: 4px;
  resize: none;
  min-height: 40px;
  max-height: 120px;
}

.send-btn {
  padding: 0.5rem 1rem;
  background: #2563eb;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.send-btn:hover {
  background: #1d4ed8;
}

@keyframes pulse {
  0% { opacity: 1; }
  50% { opacity: 0.5; }
  100% { opacity: 1; }
}
</style> 