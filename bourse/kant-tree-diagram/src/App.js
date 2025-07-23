// src/App.js
import React from "react";
import KantPhilosophyTree from "./KantTree";
import "./App.css"; // یا index.css اگر استایل‌های عمومی در آنجاست

function App() {
  return (
    <div
      className="App"
      style={{ width: "100%", height: "100vh", margin: 0, padding: 0 }}
    >
      <KantPhilosophyTree />
    </div>
  );
}

export default App;
