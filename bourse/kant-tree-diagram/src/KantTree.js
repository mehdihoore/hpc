// src/KantTree.js
import React, { useState, useEffect, useRef } from "react";
import Tree from "react-d3-tree";
import kantData from "./kantData"; // ایمپورت داده‌ها

// استایل برای کانتینر اصلی درخت
const containerStyles = {
  width: "100vw",
  height: "100vh",
  display: "flex",
  justifyContent: "center",
  alignItems: "center",
  direction: "ltr", // مهم برای چیدمان صحیح خود کتابخانه D3
};

// استایل و تنظیمات برای foreignObject که متن فارسی را رندر می‌کند
const foreignObjectProps = {
  width: 180, // عرض مستطیل حاوی متن
  height: 120, // ارتفاع مستطیل حاوی متن
  x: -90, // تنظیم موقعیت برای وسط‌چین کردن افقی نسبت به نقطه نود
  y: 20, // فاصله از نقطه نود (برای قرار گرفتن زیر دایره نود)
};

// کامپوننت سفارشی برای رندر هر نود (برای پشتیبانی بهتر از فارسی و استایل‌دهی)
const renderForeignObjectNode = ({
  nodeDatum,
  toggleNode,
  foreignObjectProps,
}) => (
  <g>
    {/* دایره یا شکل دیگر برای نمایش نود */}
    <circle
      r={15}
      fill={nodeDatum.children ? "lightsteelblue" : "#fff"}
      stroke="steelblue"
      strokeWidth="2px"
      onClick={toggleNode} // کلیک روی دایره باعث باز/بسته شدن نود می‌شود
    />
    {/* استفاده از foreignObject برای رندر کردن محتوای HTML (متن فارسی) */}
    <foreignObject {...foreignObjectProps}>
      <div
        style={{
          height: "100%", // برای پر کردن ارتفاع foreignObject
          display: "flex",
          flexDirection: "column",
          justifyContent: "center",
          alignItems: "center",
          border: "1px solid steelblue",
          borderRadius: "8px",
          backgroundColor: "white",
          padding: "8px",
          textAlign: "center",
          direction: "rtl", // اعمال جهت راست به چپ برای متن فارسی
          fontSize: "13px",
          color: "#333",
          boxShadow: "0 2px 4px rgba(0,0,0,0.1)",
          overflowWrap: "break-word",
          wordWrap: "break-word", // برای شکستن کلمات طولانی
          whiteSpace: "normal", // اطمینان از اینکه متن چند خطی می‌شود
        }}
      >
        {nodeDatum.name}
      </div>
    </foreignObject>
  </g>
);

export default function KantPhilosophyTree() {
  const [translate, setTranslate] = useState({ x: 0, y: 0 });
  const treeWrapperRef = useRef(null);

  // برای وسط‌چین کردن اولیه درخت
  useEffect(() => {
    if (treeWrapperRef.current) {
      const dimensions = treeWrapperRef.current.getBoundingClientRect();
      setTranslate({
        x: dimensions.width / 2,
        y: dimensions.height * 0.1, // شروع از کمی پایین‌تر از بالا
      });
    }
  }, []);

  return (
    <div style={containerStyles} ref={treeWrapperRef}>
      {/* تنها زمانی Tree را رندر کن که translate مقدار اولیه مناسبی گرفته باشد */}
      {translate.x !== 0 && (
        <Tree
          data={kantData}
          orientation="vertical"
          pathFunc="elbow" // 'straight', 'elbow', 'step'
          collapsible={true}
          initialDepth={1} // عمق اولیه باز بودن چارت
          translate={translate}
          separation={{ siblings: 1.5, nonSiblings: 2 }} // تنظیم فاصله بین نودها
          nodeSize={{ x: 220, y: 180 }} // اندازه فضای اختصاص داده شده به هر نود (برای جلوگیری از همپوشانی)
          zoomable={true} // فعال کردن قابلیت زوم
          scaleExtent={{ min: 0.1, max: 2 }} // محدوده زوم
          renderCustomNodeElement={(rd3tProps) =>
            renderForeignObjectNode({ ...rd3tProps, foreignObjectProps })
          }
          depthFactor={150} // فاصله عمودی بین سطوح
          centeringTransitionDuration={800}
          // styles={{ // می‌توانید استایل‌های لینک و نود را هم از اینجا تغییر دهید
          //   links: { stroke: 'red', strokeWidth: 2 },
          //   nodes: {
          //     node: { circle: { fill: '#f00' } },
          //     leafNode: { circle: { fill: '#0f0' } },
          //   },
          // }}
        />
      )}
    </div>
  );
}
