// __mocks__/react-d3-tree.js
import React from "react";

const MockTree = (props) => {
  // می‌توانید یک کامپوننت ساده یا حتی یک div خالی برگردانید
  // بسته به اینکه در تست خود به چه چیزی نیاز دارید
  return <div data-testid="mock-tree">{JSON.stringify(props.data)}</div>;
};

export default MockTree;
