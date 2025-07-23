document.addEventListener("DOMContentLoaded", () => {
  // Load initial page (dashboard)
  showPage("dashboard");
});

function showPage(pageId) {
  const pageContent = document.getElementById("page-content");
  const menuLinks = document.querySelectorAll(".menu ul li a");

  // Remove active class from all menu links
  menuLinks.forEach((link) => link.classList.remove("active"));

  // Add active class to the current menu link
  const currentLink = document.querySelector(
    `.menu ul li a[onclick="showPage('${pageId}')"]`
  );
  if (currentLink) {
    currentLink.classList.add("active");
  }

  let content = "<h1>صفحه مورد نظر یافت نشد</h1>";

  if (pageId === "dashboard") {
    `
            <h1>داشبورد مدیریتی</h1>
            <button class=\"btn\" onclick=\"showPage(\'dashboard\')\">داشبورد مدیریتی</button>
            <div class="grid-container">
            
                <div class="card">
                    <h3>تعداد کل پنل‌ها</h3>
                    <p>۱۲۰۰</p>
                </div>
                <div class="card">
                    <h3>پنل‌های بازرسی شده</h3>
                    <p>۷۵۰ (۶۲.۵%)</p>
                </div>
                <div class="card">
                    <h3>پنل‌های دارای ایراد</h3>
                    <p>۱۲۰</p>
                </div>
                <div class="card">
                    <h3>ایرادات اصلاح شده</h3>
                    <p>۸۵</p>
                </div>
            </div>
            <h2>آخرین فعالیت‌ها</h2>
            <table>
                <thead>
                    <tr>
                        <th>شرح فعالیت</th>
                        <th>کاربر</th>
                        <th>تاریخ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>بازرسی پنل GFRC-Z7-A-001</td>
                        <td>مشاور نما ۱</td>
                        <td>۱۴۰۳/۰۲/۲۵</td>
                    </tr>
                    <tr>
                        <td>ثبت ایراد برای CW-A-L2-005</td>
                        <td>مشاور نوی ۱</td>
                        <td>۱۴۰۳/۰۲/۲۴</td>
                    </tr>
                    <tr>
                        <td>اصلاح ایراد پنل GFRC-Z7-B-010</td>
                        <td>پیمانکار ۱</td>
                        <td>۱۴۰۳/۰۲/۲۳</td>
                    </tr>
                </tbody>
            </table>
        `;
  } else if (pageId === "elements") {
    content = `
            <h1>مدیریت المان‌های نما</h1>
            
           <button class=\"btn\" onclick=\"showPage(\'elements\')\">افزودن المان جدید</button>
            <table>
                <thead>
                    <tr>
                        <th>کد المان</th>
                        <th>نوع نما</th>
                        <th>زون</th>
                        <th>محور</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>GFRC-Z7-A-001</td>
                        <td>GFRC</td>
                        <td>۷</td>
                        <td>A</td>
                        <td>بازرسی شده - سالم</td>
                        <td><a href="#">مشاهده</a> | <a href="#">ویرایش</a></td>
                    </tr>
                    <tr>
                        <td>CW-A-L2-005</td>
                        <td>کرتین وال</td>
                        <td>A</td>
                        <td>L2</td>
                        <td>دارای ایراد</td>
                        <td><a href="#">مشاهده</a> | <a href="#">ویرایش</a></td>
                    </tr>
                    <tr>
                        <td>WW-B-03-012</td>
                        <td>ویندو وال</td>
                        <td>B</td>
                        <td>۰۳</td>
                        <td>منتظر بازرسی</td>
                        <td><a href="#">مشاهده</a> | <a href="#">ویرایش</a></td>
                    </tr>
                </tbody>
            </table>
        `;
  } else if (pageId === "inspections") {
    `
            <h1>لیست بازرسی‌ها</h1>
            <button class=\"btn\" onclick=\"showPage(\'new_inspection\')\">شروع بازرسی جدید</button>
            <table>
                <thead>
                    <tr>
                        <th>کد المان</th>
                        <th>تاریخ بازرسی</th>
                        <th>بازرس (نما)</th>
                        <th>بازرس (نوی)</th>
                        <th>وضعیت کلی</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>GFRC-Z7-A-001</td>
                        <td>۱۴۰۳/۰۲/۲۵</td>
                        <td>مشاور نما ۱</td>
                        <td>مشاور نوی ۲</td>
                        <td>سالم</td>
                        <td><a href=\"#\">مشاهده جزئیات</a></td>
                    </tr>
                    <tr>
                        <td>CW-A-L2-005</td>
                        <td>۱۴۰۳/۰۲/۲۴</td>
                        <td>مشاور نما ۲</td>
                        <td>مشاور نوی ۱</td>
                        <td>دارای ایراد</td>
                        <td><a href=\"#\">مشاهده جزئیات</a></td>
                    </tr>
                </tbody>
            </table>
        `;
  } else if (pageId === "new_inspection") {
    content = `
            <h1>فرم بازرسی جدید / ویرایش بازرسی</h1>
            <button class=\"btn\" onclick=\"showPage(\'new_inspection\')\">فرم بازرسی جدید / ویرایش بازرسی</button>
            <form>
                <div class="form-group">
                    <label for="element_code">کد المان</label>
                    <select id="element_code" name="element_code">
                        <option value="GFRC-Z7-A-001">GFRC-Z7-A-001</option>
                        <option value="CW-A-L2-005">CW-A-L2-005</option>
                        <option value="WW-B-03-012">WW-B-03-012</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="inspection_date">تاریخ بازرسی</label>
                    <input type="date" id="inspection_date" name="inspection_date">
                </div>
                <div class="form-group">
                    <label>چک لیست (نمونه آیتم‌ها - GFRC)</label>
                    <div><input type="checkbox" id="item1" name="item1"><label for="item1">بررسی مختصات و موقعیت</label></div>
                    <div><input type="checkbox" id="item2" name="item2"><label for="item2">بررسی اتصالات کیل</label></div>
                    <div><input type="checkbox" id="item3" name="item3"><label for="item3">بررسی زیرسازی فلزی</label></div>
                    <div><label for="item4_consultant1">نظر مشاور نما برای آیتم ۱:</label><input type="text" id="item4_consultant1"></div>
                    <div><label for="item4_consultant2">نظر مشاور نوی برای آیتم ۱:</label><input type="text" id="item4_consultant2"></div>
                </div>
                <div class="form-group">
                    <label for="remarks">ملاحظات کلی</label>
                    <textarea id="remarks" name="remarks"></textarea>
                </div>
                <div class="form-group">
                    <label for="image_upload">پیوست تصویر</label>
                    <input type="file" id="image_upload" name="image_upload">
                </div>
                <button type="submit" class="btn">ذخیره بازرسی</button>
            </form>
        `;
  } else if (pageId === "checklists") {
    `
            <h1>مدیریت قالب‌های چک‌لیست</h1>
            <button class=\"btn\"onclick=\"showPage(\'checklists\')\">ایجاد قالب جدید</button>
            <table>
                <thead>
                    <tr>
                        <th>نام قالب</th>
                        <th>نوع نما مرتبط</th>
                        <th>تعداد آیتم‌ها</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>چک‌لیست نمای GFRC - نسخه ۱</td>
                        <td>GFRC</td>
                        <td>۱۵</td>
                        <td><a href=\"#\">مشاهده</a> | <a href=\"#\">ویرایش</a> | <a href=\"#\">کپی</a></td>
                    </tr>
                    <tr>
                        <td>چک‌لیست کرتین وال - نسخه ۲.۱</td>
                        <td>کرتین وال</td>
                        <td>۲۲</td>
                        <td><a href=\"#\">مشاهده</a> | <a href=\"#\">ویرایش</a> | <a href=\"#\">کپی</a></td>
                    </tr>
                </tbody>
            </table>
        `;
  } else if (pageId === "users") {
    content = `
            <h1>مدیریت کاربران</h1>
            <button class="btn">افزودن کاربر جدید</button>
            <table>
                <thead>
                    <tr>
                        <th>نام کامل</th>
                        <th>نام کاربری</th>
                        <th>نقش</th>
                        <th>ایمیل</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>کاربر ادمین</td>
                        <td>admin</td>
                        <td>ادمین</td>
                        <td>admin@example.com</td>
                        <td><a href="#">ویرایش</a> | <a href="#">تغییر رمز</a></td>
                    </tr>
                    <tr>
                        <td>مشاور نمونه ۱</td>
                        <td>consultant1</td>
                        <td>مشاور نما</td>
                        <td>consultant1@example.com</td>
                        <td><a href="#">ویرایش</a> | <a href="#">تغییر رمز</a></td>
                    </tr>
                    <tr>
                        <td>پیمانکار نمونه ۱</td>
                        <td>contractor1</td>
                        <td>پیمانکار</td>
                        <td>contractor1@example.com</td>
                        <td><a href="#">ویرایش</a> | <a href="#">تغییر رمز</a></td>
                    </tr>
                </tbody>
            </table>
        `;
  } else if (pageId === "settings") {
    `
            <h1>تنظیمات سیستم</h1>
            <div class=\"form-group\">
                <label for=\"system_name\">نام سامانه</label>
                <input type=\"text\" id=\"system_name\" value=\"سامانه بازرسی نما بیمارستان خاتم\">
            </div>
            <div class=\"form-group\">
                <label for=\"notification_email\">ایمیل اطلاع‌رسانی‌ها</label>
                <input type=\"text\" id=\"notification_email\" value=\"notify@example.com\">
            </div>
            <button class=\"btn\">ذخیره تنظیمات</button>
        `;
  } else if (pageId === "login") {
    content = `
            <div class="login-page">
                <div class="login-form">
                    <h1>ورود به سامانه بازرس نما</h1>
                    <form onsubmit="event.preventDefault(); showPage('dashboard');">
                        <div class="form-group">
                            <label for="username">نام کاربری</label>
                            <input type="text" id="username" name="username" required value="consultant_user">
                        </div>
                        <div class="form-group">
                            <label for="password">رمز عبور</label>
                            <input type="password" id="password" name="password" required value="password123">
                        </div>
                        <button type="submit" class="btn">ورود</button>
                    </form>
                </div>
            </div>
        `;
  }
  pageContent.innerHTML = content;
}
